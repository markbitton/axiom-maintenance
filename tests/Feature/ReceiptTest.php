<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Part;
use App\Models\PartPrice;
use App\Models\Receipt;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\Billing\Invoicer;
use App\Services\Receipts\ReceiptBook;
use App\Services\Receipts\ReceiptReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ReceiptTest extends MaintenanceTestCase
{
    private function job($tech, string $desc = 'Toilet keeps running'): WorkOrder
    {
        return WorkOrder::create(['location_id' => $this->idaho->id, 'property_id' => $this->property->id, 'tenant_id' => $this->tenant->id,
            'description' => $desc, 'trade' => 'plumbing', 'status' => WorkOrder::STATUS_IN_PROGRESS, 'technician_id' => $tech->id,
            'scheduled_start' => now(), 'scheduled_end' => now()->addHours(2)]);
    }

    public function test_stores_are_typed_once_and_recognized_from_receipt_spellings(): void
    {
        $hd = Vendor::resolve('Home Depot');
        $this->assertSame($hd->id, Vendor::resolve('THE HOME DEPOT #1802')->id);
        $this->assertSame($hd->id, Vendor::resolve('home depot')->id);
        $this->assertContains('THE HOME DEPOT #1802', $hd->fresh()->aliases);
        $this->assertSame("Lowe's", Vendor::resolve("LOWE'S #0347")->name);
        $this->assertSame(2, Vendor::count());
    }

    public function test_tech_snaps_one_receipt_for_two_jobs_and_prices_land_on_each_part(): void
    {
        Storage::fake('local');
        $tech = $this->tech('Mike Plumber', ['plumbing' => 3]);
        $flapper = Part::where('sku', 'PLB-FLAP')->first();
        $cart = Part::where('sku', 'PLB-CART')->first();
        $a = $this->job($tech);
        $b = $this->job($tech, 'Kitchen faucet drips');
        $wa = $a->parts()->create(['part_id' => $flapper->id, 'part_name' => $flapper->name, 'quantity' => 1, 'used' => true]);
        $wb = $b->parts()->create(['part_id' => $cart->id, 'part_name' => $cart->name, 'quantity' => 1, 'suggested' => true]);

        $this->actingAs($tech->user)->get("/receipts/new?wo={$a->id}")->assertOk()->assertSee($b->reference);
        $res = $this->actingAs($tech->user)->post('/receipts', [
            'photo' => UploadedFile::fake()->image('receipt.jpg'), 'vendor' => 'THE HOME DEPOT #1802', 'total' => 31.9,
            'work_orders' => [$a->id, $b->id],
        ]);
        $receipt = Receipt::firstOrFail();
        $res->assertRedirect("/receipts/{$receipt->id}");
        Storage::disk('local')->assertExists($receipt->photo_path);
        $this->assertSame('Home Depot', $receipt->vendor->name);
        $this->assertCount(2, $receipt->workOrders);
        $this->assertCount(2, $receipt->lines, 'parts on both jobs are listed to price');

        $page = $this->actingAs($tech->user)->get("/receipts/{$receipt->id}")->assertOk()->assertSee('Toilet flapper');
        $this->actingAs($tech->user)->get("/receipts/{$receipt->id}/photo")->assertOk();

        $lines = $receipt->lines->values();
        $this->actingAs($tech->user)->put("/receipts/{$receipt->id}", [
            'vendor' => 'Home Depot', 'purchased_on' => '2026-11-09', 'total' => 31.9, 'work_orders' => [$a->id, $b->id],
            'lines' => [
                ['id' => $lines[0]->id, 'description' => $lines[0]->description, 'quantity' => 1, 'unit_price' => 6.98, 'assign' => 'wop:'.$wa->id],
                ['id' => $lines[1]->id, 'description' => $lines[1]->description, 'quantity' => 1, 'unit_price' => 19.97, 'assign' => 'wop:'.$wb->id],
                ['description' => 'Plumbers putty', 'quantity' => 1, 'unit_price' => 4.95, 'assign' => 'wo:'.$b->id],
            ],
        ])->assertRedirect();

        $this->assertSame(6.98, $wa->fresh()->unit_cost);
        $this->assertSame(19.97, $wb->fresh()->unit_cost);
        $this->assertTrue($wb->fresh()->used, 'a priced suggested part counts as used');
        $this->assertSame($receipt->vendor_id, $wa->fresh()->vendor_id);
        $putty = Part::where('name', 'Plumbers putty')->first();
        $this->assertNotNull($putty, 'unknown items join the parts list');
        $this->assertTrue($b->parts()->where('part_id', $putty->id)->where('used', true)->exists());
        $this->assertSame(3, PartPrice::count());
        $this->assertEquals(6.98, $flapper->fresh()->unit_cost, 'catalog cost follows the latest price paid');

        // Invoices use what was paid.
        Invoice::query()->delete();
        $inv = app(Invoicer::class)->create($a->fresh(), null);
        $this->assertSame('6.98', $inv->parts_total);
    }

    public function test_receipt_lines_match_parts_on_the_jobs(): void
    {
        $tech = $this->tech('Mike Plumber', ['plumbing' => 3]);
        $flapper = Part::where('sku', 'PLB-FLAP')->first();
        $a = $this->job($tech);
        $b = $this->job($tech, 'Kitchen faucet drips');
        $wa = $a->parts()->create(['part_id' => $flapper->id, 'part_name' => $flapper->name, 'quantity' => 1, 'used' => true]);
        $wb = $b->parts()->create(['part_name' => 'Faucet cartridge (universal)', 'quantity' => 1, 'used' => true]);
        $jobs = WorkOrder::with('parts')->whereIn('id', [$a->id, $b->id])->get();
        $book = app(ReceiptBook::class);

        $this->assertSame($wa->id, $book->match('KOHLER TOILET FLAPPER 2IN', null, $jobs)['work_order_part_id']);
        $this->assertSame($wb->id, $book->match('UNIV FAUCET CARTRIDGE', null, $jobs)['work_order_part_id']);
        $this->assertNull($book->match('GORILLA TAPE', null, $jobs)['work_order_part_id']);
    }

    public function test_ai_reading_fills_store_and_lines_when_turned_on(): void
    {
        Storage::fake('local');
        $tech = $this->tech('Mike Plumber', ['plumbing' => 3]);
        $flapper = Part::where('sku', 'PLB-FLAP')->first();
        $a = $this->job($tech);
        $wa = $a->parts()->create(['part_id' => $flapper->id, 'part_name' => $flapper->name, 'quantity' => 1, 'used' => true]);
        $this->app->instance(ReceiptReader::class, new class extends ReceiptReader
        {
            public function enabled(): bool
            {
                return true;
            }

            public function read(string $bytes, string $mediaType): ?array
            {
                return ['vendor' => "LOWE'S #0347", 'date' => '2026-11-08', 'subtotal' => 10.5, 'tax' => 0.63, 'total' => 11.13, 'lines' => [
                    ['description' => 'TOILET FLAPPER UNIVERSAL', 'sku' => '123456', 'quantity' => 1, 'unit_price' => 7.48, 'amount' => 7.48],
                    ['description' => 'TEFLON TAPE', 'sku' => null, 'quantity' => 1, 'unit_price' => 3.02, 'amount' => 3.02],
                ]];
            }
        });

        $this->actingAs($tech->user)->post('/receipts', ['photo' => UploadedFile::fake()->image('r.jpg'), 'work_orders' => [$a->id]])->assertRedirect();
        $r = Receipt::with('lines', 'vendor')->firstOrFail();
        $this->assertSame("Lowe's", $r->vendor->name);
        $this->assertSame('ai', $r->read_by);
        $this->assertSame('2026-11-08', $r->purchased_on->toDateString());
        $this->assertSame($wa->id, $r->lines[0]->work_order_part_id, 'flapper matched to the job part');
        $this->assertNull($r->lines[1]->work_order_part_id);
    }

    public function test_parts_pages_show_cheapest_store_and_other_techs_cannot_see_receipt(): void
    {
        $flapper = Part::where('sku', 'PLB-FLAP')->first();
        $hd = Vendor::resolve('Home Depot');
        $lw = Vendor::resolve("Lowe's");
        PartPrice::create(['part_id' => $flapper->id, 'vendor_id' => $hd->id, 'unit_cost' => 6.98, 'purchased_on' => '2026-10-01']);
        PartPrice::create(['part_id' => $flapper->id, 'vendor_id' => $lw->id, 'unit_cost' => 7.48, 'purchased_on' => '2026-11-01']);
        $admin = $this->staff('admin');
        $this->actingAs($admin)->get('/parts')->assertOk()->assertSee('$6.98')->assertSee('Home Depot');
        $this->actingAs($admin)->get("/parts/{$flapper->id}")->assertOk()->assertSee('Cheapest at');
        $this->actingAs($admin)->put("/parts/{$flapper->id}", ['name' => 'Toilet flapper', 'unit_cost' => 8])->assertRedirect();
        $this->assertEquals(8, $flapper->fresh()->unit_cost);

        $mike = $this->tech('Mike Plumber', ['plumbing' => 3]);
        $sam = $this->tech('Sam Fixit', ['general' => 3]);
        $receipt = Receipt::create(['uploaded_by_user_id' => $mike->user->id, 'technician_id' => $mike->id]);
        $receipt->workOrders()->attach($this->job($mike)->id);
        $this->actingAs($sam->user)->get("/receipts/{$receipt->id}")->assertForbidden();
        $this->actingAs($sam->user)->get('/parts')->assertForbidden();
    }
}
