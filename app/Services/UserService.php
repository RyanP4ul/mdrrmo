<?php

namespace App\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class UserService
{
    public function getUsers(array $validated = [])
    {
      $columns = ['id', 'first_name', 'last_name', 'email', 'contact_number', 'status', 'house_no', 'street', 'barangay', 'city', 'province', 'created_at'];
      $accounts = DB::table('admins')->select($columns)->selectRaw('? as role', ['admin'])
          ->unionAll(DB::table('dispatchers')->select($columns)->selectRaw('? as role', ['dispatcher']))
          ->unionAll(DB::table('drivers')->select($columns)->selectRaw('? as role', ['driver/responder']))
          ->unionAll(DB::table('residents')->select($columns)->selectRaw('? as role', ['resident']));

      $query = DB::query()->fromSub($accounts, 'accounts');

      if (! empty($validated['search'])) {
        $search = trim($validated['search']);

        $query->where(function (Builder $builder) use ($search) {
            $builder
                ->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%");

            if (ctype_digit($search)) {
                $builder->orWhere('id', (int) $search);
            }
        });
      }

      if (! empty($validated['role'])) {
          $query->where('role', $validated['role']);
      }

      $perPage = (int) ($validated['per_page'] ?? 10);

      return response()->json($query
          ->orderByDesc('created_at')
          ->orderByDesc('id')
          ->paginate($perPage)
          ->withQueryString());
    }
}
