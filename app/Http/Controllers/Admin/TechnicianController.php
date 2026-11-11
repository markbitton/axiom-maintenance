<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Skill;
use App\Models\StockLocation;
use App\Models\Technician;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TechnicianController extends Controller
{
    public function index()
    {
        return view('admin.technicians', [
            'users' => User::with('technician.skills', 'location')->orderBy('role')->orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        return view('admin.technician-form', ['user' => new User(['role' => 'tech']), 'tech' => new Technician, ...$this->lists()]);
    }

    public function edit(User $user)
    {
        return view('admin.technician-form', ['user' => $user, 'tech' => $user->technician ?? new Technician, ...$this->lists()]);
    }

    public function store(Request $request)
    {
        return $this->save($request, new User);
    }

    public function update(Request $request, User $user)
    {
        return $this->save($request, $user);
    }

    private function save(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => ['required', 'email', Rule::unique('users')->ignore($user->id)],
            'password' => [$user->exists ? 'nullable' : 'required', 'min:10'],
            'role' => 'required|in:'.implode(',', User::ROLES),
            'phone' => 'nullable|string|max:30',
            'location_id' => 'required|exists:locations,id',
            'active' => 'boolean',
            'start_address' => 'nullable|string|max:200',
            'start_lat' => 'nullable|numeric|between:-90,90',
            'start_lng' => 'nullable|numeric|between:-180,180',
            'work_start' => 'nullable|date_format:H:i',
            'work_end' => 'nullable|date_format:H:i',
            'work_days' => 'nullable|array',
            'max_jobs_per_day' => 'nullable|integer|min:1|max:20',
            'on_call' => 'boolean',
            'skills' => 'nullable|array',
            'skills.*' => 'nullable|integer|min:0|max:3',
        ]);

        DB::transaction(function () use ($data, $user) {
            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'role' => $data['role'],
                'phone' => Phone::normalize($data['phone'] ?? null),
                'location_id' => $data['location_id'],
                'active' => $data['active'] ?? false,
            ]);
            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }
            $user->save();

            if ($data['role'] !== 'tech') {
                return;
            }
            $tech = $user->technician ?? new Technician(['user_id' => $user->id]);
            $tech->fill([
                'location_id' => $data['location_id'],
                'start_address' => $data['start_address'] ?? null,
                'start_lat' => $data['start_lat'] ?? null,
                'start_lng' => $data['start_lng'] ?? null,
                'work_start' => $data['work_start'] ?? '08:00',
                'work_end' => $data['work_end'] ?? '17:00',
                'work_days' => implode(',', $data['work_days'] ?? [1, 2, 3, 4, 5]),
                'max_jobs_per_day' => $data['max_jobs_per_day'] ?? 8,
                'on_call' => $data['on_call'] ?? false,
                'active' => $data['active'] ?? false,
            ])->save();

            $sync = [];
            foreach ($data['skills'] ?? [] as $skillId => $level) {
                if ((int) $level > 0) {
                    $sync[$skillId] = ['level' => (int) $level];
                }
            }
            $tech->skills()->sync($sync);

            StockLocation::firstOrCreate(
                ['technician_id' => $tech->id, 'type' => 'truck'],
                ['location_id' => $tech->location_id, 'name' => $user->name."'s truck"]
            );
        });

        return redirect()->route('admin.technicians')->with('status', 'Saved '.$data['name'].'.');
    }

    private function lists(): array
    {
        return ['locations' => Location::orderBy('rm_location_id')->get(), 'skills' => Skill::orderBy('name')->get()];
    }
}
