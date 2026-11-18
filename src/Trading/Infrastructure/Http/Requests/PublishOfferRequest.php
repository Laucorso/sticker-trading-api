<?php

declare(strict_types=1);

namespace Trading\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Solo valida la forma. Que no se ofrezca y pida el mismo cromo o que el autor
 * tenga repetidas son reglas de negocio: las comprueba el dominio.
 */
final class PublishOfferRequest extends FormRequest
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
            'offered_sticker_id' => ['required', 'integer', 'exists:stickers,id'],
            'requested_sticker_id' => ['required', 'integer', 'exists:stickers,id'],
        ];
    }

    public function authorId(): int
    {
        return (int) $this->user()->id;
    }

    public function offeredStickerId(): int
    {
        return $this->integer('offered_sticker_id');
    }

    public function requestedStickerId(): int
    {
        return $this->integer('requested_sticker_id');
    }
}
