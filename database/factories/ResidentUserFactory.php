<?php

namespace Database\Factories;

use App\Models\ResidentUser;

/**
 * @extends AccountUserFactory<ResidentUser>
 */
class ResidentUserFactory extends AccountUserFactory
{
    protected $model = ResidentUser::class;

    /**
     * Indicate that the resident registration has been accepted.
     */
    public function accepted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'registration_status' => 'accepted',
        ]);
    }
}
