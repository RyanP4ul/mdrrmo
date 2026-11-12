<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AdminUser;
use App\Models\DispatcherUser;
use App\Models\DriverResponderUser;
use App\Models\ResidentNotification;
use App\Models\ResidentUser;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only('emp_search', 'role', 'status', 'res_search', 'reg');
        $employeeRows = $this->employeeAccounts()
            ->when($filters['role'] ?? null, fn (Builder $query, string $role) => $query->where('role', $role))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['emp_search'] ?? null, fn (Builder $query, string $search) => $query->where(function (Builder $query) use ($search): void {
                $query->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('contact_number', 'like', "%{$search}%")->orWhere('phone_number', 'like', "%{$search}%");
            }))
            ->orderBy('last_name')
            ->orderBy('id')
            ->paginate(8, ['*'], 'emp_page')
            ->withQueryString()
            ->through(function (object $user): array {
                $data = (array) $user;
                $data['full_name'] = trim("{$user->first_name} {$user->last_name}");
                $data['account_key'] = Account::keyForRoleAndId($user->role, (int) $user->id);

                return $data;
            });

        $residents = ResidentUser::query()
            ->when($filters['res_search'] ?? null, fn ($query, string $search) => $query->where(fn ($query) => $query
                ->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('contact_number', 'like', "%{$search}%")
                ->orWhere('barangay', 'like', "%{$search}%")))
            ->when($filters['reg'] ?? null, fn ($query, string $status) => $query->where('registration_status', $status))
            ->latest()
            ->paginate(8, ['*'], 'res_page')
            ->withQueryString();

        return Inertia::render('Admin/Users/Index', [
            'filters' => $filters,
            'employees' => $employeeRows,
            'residents' => $residents->through(fn (ResidentUser $resident): array => [
                ...$resident->toArray(),
                'account_key' => Account::keyFor($resident),
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $password = Str::password(10, symbols: false);
        $model = Account::modelForRole($data['role']);
        unset($data['role']);
        $user = (new $model($data))->forceFill(['password' => $password]);
        $user->save();
        Audit::record('CREATE_USER', "Created {$user->role} account for {$user->full_name}");

        return back()->with('success', "User created. Temporary password: {$password}");
    }

    public function update(Request $request, string $account)
    {
        $user = Account::findByKey($account);
        abort_unless($user, 404);

        $data = $this->validated($request, $user);

        if ($data['role'] !== $user->role) {
            throw ValidationException::withMessages([
                'role' => 'An account role cannot be changed after creation.',
            ]);
        }

        unset($data['role']);
        $user->fill($data)->save();
        Audit::record('UPDATE_USER', "Updated {$user->full_name}");

        return back()->with('success', 'User updated.');
    }

    public function destroy(Request $request, string $account)
    {
        $user = Account::findByKey($account);
        abort_unless($user, 404);
        abort_if(
            Account::keyFor($user) === Account::keyFor($request->user()),
            422,
            'You cannot delete your own account.',
        );

        $name = $user->full_name;
        $user->delete();
        Audit::record('DELETE_USER', "Deleted {$name}");

        return back()->with('success', 'User deleted.');
    }

    public function review(Request $request, string $account)
    {
        $user = Account::findByKey($account);
        abort_unless($user instanceof ResidentUser, 404);

        $decision = $request->validate(['decision' => ['required', 'in:accepted,declined']])['decision'];
        $user->forceFill([
            'registration_status' => $decision,
            'status' => $decision === 'accepted' ? 'active' : 'inactive',
        ])->save();

        ResidentNotification::create([
            'user_id' => $user->getKey(),
            'type' => 'general',
            'title' => $decision === 'accepted' ? 'Registration approved' : 'Registration declined',
            'message' => $decision === 'accepted'
                ? 'You can now submit reports and book services.'
                : 'Your registration could not be verified.',
        ]);
        Audit::record('UPDATE_USER', "Registration of {$user->full_name} {$decision}");

        return back()->with('success', "Registration {$decision}.");
    }

    private function employeeAccounts(): Builder
    {
        $columns = [
            'id', 'first_name', 'last_name', 'middle_initial', 'email', 'contact_number',
            'date_of_birth', 'sex', 'status', 'barangay', 'city', 'province',
            'id_type', 'id_document_path', 'registration_status',
        ];
        $accounts = AdminUser::query()->select($columns)->selectRaw('? as role, NULL as phone_number', ['admin'])
            ->unionAll(DispatcherUser::query()->select($columns)->selectRaw('? as role, phone_number', ['dispatcher']))
            ->unionAll(DriverResponderUser::query()->select($columns)->selectRaw('? as role, NULL as phone_number', ['driver/responder']));

        return DB::query()->fromSub($accounts, 'employee_accounts');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?User $user = null): array
    {
        $emailRules = ['required', 'email'];

        foreach ([
            'admins' => AdminUser::class,
            'dispatchers' => DispatcherUser::class,
            'drivers' => DriverResponderUser::class,
            'residents' => ResidentUser::class,
        ] as $table => $model) {
            $uniqueRule = Rule::unique($table, 'email');

            if ($user instanceof $model) {
                $uniqueRule->ignore($user->getKey());
            }

            $emailRules[] = $uniqueRule;
        }

        return $request->validate([
            'first_name' => ['required', 'string', 'max:60'],
            'last_name' => ['required', 'string', 'max:60'],
            'middle_initial' => ['nullable', 'string', 'max:2'],
            'email' => $emailRules,
            'contact_number' => ['required', 'string', 'max:20'],
            'phone_number' => ['exclude_unless:role,dispatcher', 'nullable', 'string', 'max:28', 'regex:/^\+?[0-9][0-9 ()-]{2,24}$/D'],
            'date_of_birth' => ['nullable', 'date'],
            'sex' => ['nullable', 'in:Male,Female'],
            'role' => ['required', 'in:admin,dispatcher,driver/responder,resident'],
            'status' => ['required', 'in:active,inactive,suspended'],
            'barangay' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'province' => ['nullable', 'string'],
        ]);
    }
}
