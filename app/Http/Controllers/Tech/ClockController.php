<?php

namespace App\Http\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Services\Time\TimeClock;
use Illuminate\Http\Request;

/** Employee clock, breaks and the 15-minute GPS check-in. Every action needs the phone's location. */
class ClockController extends Controller
{
    public function __construct(private TimeClock $clock) {}

    public function in(Request $request)
    {
        $this->clock->clockIn($this->tech($request), self::gps($request));

        return back()->with('status', 'Clocked in. Have a good day.');
    }

    public function out(Request $request)
    {
        $this->clock->clockOut($this->tech($request), self::gps($request));

        return back()->with('status', 'Clocked out.');
    }

    public function breakStart(Request $request)
    {
        $tech = $this->tech($request);
        if (! $this->clock->shift($tech)) {
            return back()->withErrors(['clock' => 'Clock in for the day first.']);
        }
        $this->clock->startBreak($tech, self::gps($request));

        return back()->with('status', 'On break. Your billable clock is stopped.');
    }

    public function breakEnd(Request $request)
    {
        $resumed = $this->clock->endBreak($this->tech($request), self::gps($request));

        return back()->with('status', $resumed ? 'Back from break. Job clock restarted on '.$resumed->workOrder->reference.'.' : 'Back from break.');
    }

    public function ping(Request $request)
    {
        $tech = $this->tech($request);
        if (! $this->clock->shift($tech)) {
            return response()->json(['ok' => false, 'reason' => 'off the clock']);
        }
        $this->clock->ping($tech, self::gps($request));

        return response()->json(['ok' => true]);
    }

    private function tech(Request $request)
    {
        return $request->user()->technician()->with('location')->firstOrFail();
    }

    /** The phone's location from the form. Without it the clocks don't move. */
    public static function gps(Request $request): array
    {
        $data = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric|min:0',
        ], ['lat.required' => 'Turn on Location Services for this app to use the clock.', 'lng.required' => 'Turn on Location Services for this app to use the clock.']);

        return ['lat' => (float) $data['lat'], 'lng' => (float) $data['lng'], 'accuracy' => isset($data['accuracy']) ? (int) round($data['accuracy']) : null];
    }
}
