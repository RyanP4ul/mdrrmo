<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AdminUser;
use App\Models\ResidentNotification;
use App\Models\ResidentUser;
use App\Models\User;
use App\Support\Audit;
use App\Support\MobilePage;
use App\Support\MobileValidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MobileStaffAccountsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['role' => ['required', 'in:resident,driver/responder,dispatcher,admin'], 'registration_status' => ['nullable', 'in:pending,accepted,declined']]);
        $model = Account::modelForRole($data['role']);
        $query = $model::query();
        if ($data['role'] === 'resident' && ! empty($data['registration_status'])) {
            $query->where('registration_status', $data['registration_status']);
        }

        return response()->json(MobilePage::make($query, fn ($user) => $this->data($user), $request));
    }

    private function data(User $user): array
    {
        return $user->only(['id', 'first_name', 'last_name', 'email', 'contact_number', 'status', 'registration_status', 'barangay', 'city', 'province', 'created_at']) + ['account_key' => $user->account_key, 'role' => $user->role, 'name' => $user->full_name];
    }

    public function save(Request $request, ?string $account = null): JsonResponse
    {
        $user = $account ? Account::findByKey($account) : null;
        abort_if($account && ! $user, 404);
        MobileValidation::normalizePhone($request, 'contact_number');
        $emailRules = ['required', 'email', 'max:255'];
        foreach (Account::classes() as $model) {
            $emailRules[] = Rule::unique((new $model)->getTable(), 'email')->ignore($user instanceof $model ? $user->id : null);
        }
        $data = $request->validate(['first_name' => ['required', 'string', 'max:60'], 'last_name' => ['required', 'string', 'max:60'], 'email' => $emailRules, 'contact_number' => MobileValidation::phoneRules(),
            'role' => ['required', 'in:resident,driver/responder,dispatcher,admin'], 'status' => ['required', 'in:active,inactive,suspended'],
            'password' => [$user ? 'prohibited' : 'required', 'string', 'min:8', 'max:128'], 'barangay' => ['nullable', 'string', 'max:120'], 'city' => ['nullable', 'string', 'max:120'], 'province' => ['nullable', 'string', 'max:120']]);

        return DB::transaction(function () use ($request, $user, $data): JsonResponse {
            if ($user) {
                if ($user->hasRole('admin')) {
                    AdminUser::where('status', 'active')->orderBy('id')->lockForUpdate()->get();
                }
                $user = $user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                abort_unless($data['role'] === $user->role, 422, 'An account role cannot change after creation.');
                abort_if($user->account_key === $request->user()->account_key && $data['status'] !== 'active', 422, 'You cannot disable your own account.');
                abort_if($user->hasRole('admin') && $data['status'] !== 'active' && $user->status === 'active' && AdminUser::where('status', 'active')->count() <= 1, 422, 'Keep at least one active administrator.');
            } else {
                $model = Account::modelForRole($data['role']);
                $user = new $model;
            }
            $created = ! $user->exists;
            unset($data['role']);
            if (! $created) {
                unset($data['password']);
            }
            $user->fill($data);
            if ($created && $user->hasRole('resident')) {
                $user->registration_status = 'pending';
            }
            $user->save();
            if ($user->status !== 'active') {
                $user->tokens()->delete();
            }
            Audit::record($created ? 'CREATE_USER' : 'UPDATE_USER', 'Mobile staff account '.$user->account_key);

            return response()->json($this->data($user), $created ? 201 : 200);
        });
    }

    public function review(Request $request, string $account): JsonResponse
    {
        $user = Account::findByKey($account);
        abort_unless($user instanceof ResidentUser, 404);
        $data = $request->validate(['decision' => ['required', 'in:accepted,declined']]);

        return DB::transaction(function () use ($user, $data): JsonResponse {
            $user = ResidentUser::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($user->registration_status !== $data['decision']) {
                $user->update(['registration_status' => $data['decision'], 'status' => $data['decision'] === 'accepted' ? 'active' : 'inactive']);
                if ($user->status !== 'active') {
                    $user->tokens()->delete();
                }
                ResidentNotification::create(['user_id' => $user->id, 'type' => 'general', 'title' => 'Registration '.$data['decision'], 'message' => 'Your registration was '.$data['decision'].'.']);
                Audit::record('REVIEW_RESIDENT', $user->account_key.' '.$data['decision']);
            }

            return response()->json($this->data($user));
        });
    }
}
