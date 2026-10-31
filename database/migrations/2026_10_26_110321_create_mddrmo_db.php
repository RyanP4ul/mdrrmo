<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Residents and employees share the users table and are separated by role.
        Schema::table('users', function (Blueprint $t) {
            $t->string('name')->nullable()->change();
            $t->string('first_name')->after('id');
            $t->string('last_name')->after('first_name');
            $t->string('middle_initial', 2)->nullable();
            $t->string('contact_number', 20)->nullable();
            $t->date('date_of_birth')->nullable();
            $t->enum('sex', ['Male', 'Female'])->nullable();
            $t->string('house_no')->nullable();
            $t->string('street')->nullable();
            $t->string('barangay')->nullable();
            $t->string('city')->nullable();
            $t->string('province')->nullable();
            $t->string('id_type')->nullable();
            $t->string('id_document_path')->nullable();
            $t->string('avatar_path')->nullable();
            $t->enum('role', ['admin', 'dispatcher', 'driver/responder', 'resident'])->default('resident')->index();
            $t->enum('status', ['active', 'inactive', 'suspended'])->default('active')->index();
            $t->enum('registration_status', ['pending', 'accepted', 'declined'])->nullable()->index();
        });

        Schema::create('incident_types', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->text('description');
            $t->enum('priority', ['low', 'medium', 'high', 'critical'])->default('medium');
            $t->string('color', 9)->default('#6b7280'); // used by charts/map
            $t->timestamps();
        });

        Schema::create('response_teams', function (Blueprint $t) {
            $t->id();
            $t->string('team_name')->unique();
            $t->enum('status', ['active', 'inactive', 'on-leave', 'off-duty'])->default('active')->index();
            $t->json('specializations')->nullable();
            $t->timestamps();
        });

        Schema::create('team_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('response_team_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name');
            $t->string('role')->nullable();
            $t->enum('status', ['active', 'inactive', 'on-leave', 'off-duty'])->default('active');
            $t->timestamps();
        });

        Schema::create('shifts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('response_team_id')->constrained()->cascadeOnDelete();
            $t->dateTime('start_time');
            $t->dateTime('end_time');
            $t->enum('status', ['on-shift', 'off-shift', 'on-break'])->default('off-shift');
            $t->timestamps();
        });

        Schema::create('shift_team_member', function (Blueprint $t) {
            $t->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $t->foreignId('team_member_id')->constrained()->cascadeOnDelete();
            $t->primary(['shift_id', 'team_member_id']);
        });

        Schema::create('emergency_reports', function (Blueprint $t) {
            $t->id();
            $t->string('reference')->unique();                  // e.g. RPT-2026-0001
            $t->foreignId('incident_type_id')->constrained();
            $t->string('location');
            $t->decimal('lat', 10, 7);
            $t->decimal('lng', 10, 7);
            $t->text('description');
            $t->enum('status', ['pending', 'acknowledged', 'dispatched', 'resolved', 'invalid'])->default('pending');
            $t->enum('priority', ['low', 'medium', 'high', 'critical'])->default('medium');
            $t->enum('source', ['emergency-call', 'mobile-app'])->default('mobile-app');
            $t->enum('reporter_type', ['victim', 'witness'])->nullable();
            $t->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('reporter_name');
            $t->string('reporter_contact')->nullable();
            $t->json('victim_info')->nullable();
            $t->json('witness_info')->nullable();
            $t->foreignId('response_team_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('assigned_driver_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('acknowledged_at')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamp('reported_at')->useCurrent();
            $t->timestamps();
            $t->index(['status', 'priority']);
            $t->index('reported_at');
        });

        // Admin "patient care report" (driver + emergency sections) – 1:1 with a report
        Schema::create('report_forms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('emergency_report_id')->unique()->constrained()->cascadeOnDelete();
            $t->json('driver_section');
            $t->json('emergency_section');
            $t->timestamps();
        });

        Schema::create('announcements', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->enum('category', ['Weather Advisory', 'Evacuation', 'Traffic Advisory', 'Information', 'Relief Operations']);
            $t->text('description');
            $t->string('image_path')->nullable();
            $t->foreignId('posted_by')->constrained('users');
            $t->timestamps();
        });

        Schema::create('service_schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('resident_id')->constrained('users')->cascadeOnDelete();
            $t->string('service_type');
            $t->date('preferred_date');
            $t->string('preferred_time');
            $t->string('address');
            $t->text('notes')->nullable();
            $t->enum('status', ['pending', 'acknowledged', 'confirmed', 'declined', 'completed', 'cancelled'])->default('pending')->index();
            $t->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('acknowledged_at')->nullable();
            $t->text('decline_reason')->nullable();
            $t->timestamps();
        });

        Schema::create('resident_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('title');
            $t->text('message');
            $t->enum('type', ['schedule_confirmed', 'schedule_cancelled', 'report_update', 'general'])->default('general');
            $t->nullableMorphs('related');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });

        Schema::create('vehicles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('response_team_id')->nullable()->constrained()->nullOnDelete();
            $t->enum('vehicle_type', ['ambulance', 'fire-truck', 'rescue-van', 'police-car', 'utility-truck']);
            $t->string('plate_number')->unique();
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->enum('status', ['en-route', 'on-scene', 'available', 'returning', 'offline'])->default('available');
            $t->unsignedSmallInteger('speed')->default(0);
            $t->unsignedSmallInteger('heading')->default(0);
            $t->foreignId('assigned_report_id')->nullable()->constrained('emergency_reports')->nullOnDelete();
            $t->timestamp('last_updated_at')->nullable();
            $t->timestamps();
        });

        Schema::create('hospitals', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('address');
            $t->decimal('lat', 10, 7);
            $t->decimal('lng', 10, 7);
            $t->json('specialization')->nullable();
            $t->unsignedSmallInteger('available_beds')->default(0);
            $t->string('contact_number')->nullable();
            $t->timestamps();
        });

        $this->separateRoleAccounts();
    }

    public function down(): void
    {
        $this->restoreSharedUsers();

        foreach (['hospitals','vehicles','resident_notifications','service_schedules','announcements','report_forms','emergency_reports','shift_team_member','shifts','team_members','response_teams','incident_types'] as $tbl) {
            Schema::dropIfExists($tbl);
        }

        Schema::table('users', function (Blueprint $t) {
            $t->dropIndex(['role']);
            $t->dropIndex(['status']);
            $t->dropIndex(['registration_status']);
            $t->dropColumn([
                'first_name', 'last_name', 'middle_initial', 'contact_number', 'date_of_birth',
                'sex', 'house_no', 'street', 'barangay', 'city', 'province', 'id_type',
                'id_document_path', 'avatar_path', 'role', 'status', 'registration_status',
            ]);
        });
    }

    private function separateRoleAccounts(): void
    {
        Schema::create('account_id_sequences', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('next_id');
        });

        $lastUserId = (int) DB::table('users')->max('id');
        DB::table('account_id_sequences')->insert(['id' => 1, 'next_id' => $lastUserId]);

        foreach (['admins', 'dispatchers', 'drivers', 'residents'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->unsignedBigInteger('id')->primary();
                $table->string('first_name', 60);
                $table->string('last_name', 60);
                $table->string('middle_initial', 2)->nullable();
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->string('contact_number', 20)->nullable();
                $table->date('date_of_birth')->nullable();
                $table->enum('sex', ['Male', 'Female'])->nullable();
                $table->string('house_no')->nullable();
                $table->string('street')->nullable();
                $table->string('barangay')->nullable();
                $table->string('city')->nullable();
                $table->string('province')->nullable();
                $table->string('id_type')->nullable();
                $table->string('id_document_path')->nullable();
                $table->string('avatar_path')->nullable();
                $table->enum('status', ['active', 'inactive', 'suspended'])->default('active')->index();
                $table->enum('registration_status', ['pending', 'accepted', 'declined'])->nullable()->index();
                $table->rememberToken();
                $table->timestamps();
            });
        }

        $tableForRole = [
            'admin' => 'admins',
            'dispatcher' => 'dispatchers',
            'driver/responder' => 'drivers',
            'resident' => 'residents',
        ];

        foreach (DB::table('users')->orderBy('id')->get() as $user) {
            $tableName = $tableForRole[$user->role] ?? null;

            if ($tableName === null) {
                throw new \RuntimeException("Cannot migrate account with unknown role [{$user->role}].");
            }

            $account = (array) $user;
            unset($account['name'], $account['role']);
            DB::table($tableName)->insert($account);
        }

        $accountModels = [
            'admin' => \App\Models\AdminUser::class,
            'dispatcher' => \App\Models\DispatcherUser::class,
            'driver/responder' => \App\Models\DriverResponderUser::class,
            'resident' => \App\Models\ResidentUser::class,
        ];
        $userRoles = DB::table('users')->pluck('role', 'id');

        if (Schema::hasTable('personal_access_tokens')) {
            foreach (DB::table('personal_access_tokens')->where('tokenable_type', \App\Models\User::class)->get(['id', 'tokenable_id']) as $token) {
                $role = $userRoles[$token->tokenable_id] ?? null;

                if ($role !== null) {
                    DB::table('personal_access_tokens')->where('id', $token->id)
                        ->update(['tokenable_type' => $accountModels[$role]]);
                }
            }
        }

        $setMorphType = static function (string $table, string $typeColumn, string $idColumn) use ($userRoles, $accountModels): void {
            foreach (DB::table($table)->whereNotNull($idColumn)->get([$idColumn]) as $record) {
                $role = $userRoles[$record->{$idColumn}] ?? null;

                if ($role !== null) {
                    DB::table($table)->where($idColumn, $record->{$idColumn})
                        ->update([$typeColumn => $accountModels[$role]]);
                }
            }
        };

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->string('user_type')->nullable();
            $table->index(['user_type', 'user_id']);
        });
        $setMorphType('audit_logs', 'user_type', 'user_id');

        Schema::table('announcements', function (Blueprint $table) {
            $table->dropForeign(['posted_by']);
            $table->string('posted_by_type')->nullable();
            $table->index(['posted_by_type', 'posted_by']);
        });
        $setMorphType('announcements', 'posted_by_type', 'posted_by');

        Schema::table('emergency_reports', function (Blueprint $table) {
            $table->dropForeign(['reported_by']);
            $table->dropForeign(['assigned_driver_id']);
            $table->dropForeign(['acknowledged_by']);
            $table->string('reported_by_type')->nullable();
            $table->string('acknowledged_by_type')->nullable();
            $table->index(['reported_by_type', 'reported_by']);
            $table->index(['acknowledged_by_type', 'acknowledged_by']);
        });
        $setMorphType('emergency_reports', 'reported_by_type', 'reported_by');
        $setMorphType('emergency_reports', 'acknowledged_by_type', 'acknowledged_by');

        Schema::table('service_schedules', function (Blueprint $table) {
            $table->dropForeign(['resident_id']);
            $table->dropForeign(['acknowledged_by']);
            $table->string('acknowledged_by_type')->nullable();
            $table->index(['acknowledged_by_type', 'acknowledged_by']);
        });
        $setMorphType('service_schedules', 'acknowledged_by_type', 'acknowledged_by');

        Schema::table('resident_notifications', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
        Schema::table('team_members', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('emergency_reports', function (Blueprint $table) {
            $table->foreign('assigned_driver_id')->references('id')->on('drivers')->nullOnDelete();
        });
        Schema::table('service_schedules', function (Blueprint $table) {
            $table->foreign('resident_id')->references('id')->on('residents')->cascadeOnDelete();
        });
        Schema::table('resident_notifications', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('residents')->cascadeOnDelete();
        });
        Schema::table('team_members', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('drivers')->nullOnDelete();
        });

        Schema::drop('users');
    }

    private function restoreSharedUsers(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('first_name', 60);
            $table->string('last_name', 60);
            $table->string('middle_initial', 2)->nullable();
            $table->string('contact_number', 20)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->enum('sex', ['Male', 'Female'])->nullable();
            $table->string('house_no')->nullable();
            $table->string('street')->nullable();
            $table->string('barangay')->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('id_type')->nullable();
            $table->string('id_document_path')->nullable();
            $table->string('avatar_path')->nullable();
            $table->enum('role', ['admin', 'dispatcher', 'driver/responder', 'resident'])->default('resident')->index();
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active')->index();
            $table->enum('registration_status', ['pending', 'accepted', 'declined'])->nullable()->index();
            $table->rememberToken();
            $table->timestamps();
        });

        foreach ([
            'admins' => 'admin',
            'dispatchers' => 'dispatcher',
            'drivers' => 'driver/responder',
            'residents' => 'resident',
        ] as $tableName => $role) {
            foreach (DB::table($tableName)->orderBy('id')->get() as $account) {
                $user = (array) $account;
                $user['name'] = trim($account->first_name.' '.$account->last_name);
                $user['role'] = $role;
                DB::table('users')->insert($user);
            }
        }

        if (Schema::hasTable('personal_access_tokens')) {
            DB::table('personal_access_tokens')
                ->whereIn('tokenable_type', [
                    \App\Models\AdminUser::class,
                    \App\Models\DispatcherUser::class,
                    \App\Models\DriverResponderUser::class,
                    \App\Models\ResidentUser::class,
                ])
                ->update(['tokenable_type' => \App\Models\User::class]);
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['user_type', 'user_id']);
            $table->dropColumn('user_type');
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropIndex(['posted_by_type', 'posted_by']);
            $table->dropColumn('posted_by_type');
            $table->foreign('posted_by')->references('id')->on('users');
        });
        Schema::table('emergency_reports', function (Blueprint $table) {
            $table->dropForeign(['assigned_driver_id']);
            $table->dropIndex(['reported_by_type', 'reported_by']);
            $table->dropIndex(['acknowledged_by_type', 'acknowledged_by']);
            $table->dropColumn(['reported_by_type', 'acknowledged_by_type']);
            $table->foreign('reported_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('assigned_driver_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('acknowledged_by')->references('id')->on('users')->nullOnDelete();
        });
        Schema::table('service_schedules', function (Blueprint $table) {
            $table->dropForeign(['resident_id']);
            $table->dropIndex(['acknowledged_by_type', 'acknowledged_by']);
            $table->dropColumn('acknowledged_by_type');
            $table->foreign('resident_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('acknowledged_by')->references('id')->on('users')->nullOnDelete();
        });
        Schema::table('resident_notifications', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        Schema::table('team_members', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::drop('account_id_sequences');
        Schema::drop('admins');
        Schema::drop('dispatchers');
        Schema::drop('drivers');
        Schema::drop('residents');
    }
};
