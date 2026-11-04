<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MobileFilters
{
    public static function apply(Builder|Relation $query, Request $request): void
    {
        $table = $query->getModel()->getTable();
        $columns = match ($table) {
            'emergency_reports' => ['reference', 'location', 'description'],
            'service_schedules' => ['service_type', 'address', 'notes'],
            'resident_notifications', 'driver_notifications' => ['title', 'message'],
            'announcements' => ['title', 'description', 'category'],
            'incident_types' => ['name', 'description'],
            'response_teams' => ['team_name'],
            'vehicles' => ['plate_number'],
            'audit_logs' => ['action', 'details', 'user_name'],
            'residents', 'drivers', 'admins', 'dispatchers' => ['first_name', 'last_name', 'email', 'contact_number'],
            default => [],
        };
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:120'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        if (! empty($filters['from']) && ! empty($filters['to']) && $filters['to'] < $filters['from']) {
            throw ValidationException::withMessages(['to' => 'The end date must not precede the start date.']);
        }
        if (! empty($filters['search']) && $columns !== []) {
            $query->where(function ($q) use ($columns, $filters): void {
                foreach ($columns as $column) {
                    $q->orWhereLike($column, '%'.$filters['search'].'%');
                }
            });
        }
        $date = match ($table) {
            'emergency_reports' => 'reported_at', 'service_schedules' => 'preferred_date', default => 'created_at'
        };
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if (! empty($filters[$key])) {
                $day = Carbon::parse($filters[$key], config('mobile.timezone'));
                $bound = $date === 'preferred_date' ? $day->toDateString() : ($key === 'from' ? $day->startOfDay() : $day->endOfDay())->utc();
                $query->where($date, $operator, $bound);
            }
        }
    }
}
