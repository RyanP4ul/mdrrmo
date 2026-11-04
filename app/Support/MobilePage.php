<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;

class MobilePage
{
    public static function make(Builder|Relation $query, ?callable $transform = null, ?Request $request = null): array
    {
        if ($request) {
            MobileFilters::apply($query, $request);
        }
        $data = $request?->validate(['before' => ['nullable', 'integer', 'min:1'], 'limit' => ['nullable', 'integer', 'between:1,50']]) ?? [];
        $limit = (int) ($data['limit'] ?? 50);
        $records = $query->reorder()->when(isset($data['before']), fn (Builder $q) => $q->where('id', '<', $data['before']))
            ->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $records->count() > $limit;
        $records = $records->take($limit)->values();

        return [
            'data' => $transform ? $records->map($transform)->all() : $records->toArray(),
            'next_before' => $hasMore ? $records->last()->getKey() : null,
        ];
    }
}
