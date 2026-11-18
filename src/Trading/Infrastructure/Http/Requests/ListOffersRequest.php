<?php

declare(strict_types=1);

namespace Trading\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Trading\Application\Query\OpenOffersQuery;

/** Filtros y paginación por cursor del listado de ofertas abiertas. */
final class ListOffersRequest extends FormRequest
{
    /** La autenticación la resuelve el middleware auth:api. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'offered_sticker_id' => ['nullable', 'integer'],
            'requested_sticker_id' => ['nullable', 'integer'],
            'author_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string'],
        ];
    }

    public function toQuery(): OpenOffersQuery
    {
        return new OpenOffersQuery(
            offeredStickerId: $this->optionalInt('offered_sticker_id'),
            requestedStickerId: $this->optionalInt('requested_sticker_id'),
            authorId: $this->optionalInt('author_id'),
            perPage: $this->optionalInt('per_page') ?? OpenOffersQuery::DEFAULT_PER_PAGE,
            cursor: $this->validated('cursor'),
        );
    }

    private function optionalInt(string $key): ?int
    {
        $value = $this->validated($key);

        return $value === null ? null : (int) $value;
    }
}
