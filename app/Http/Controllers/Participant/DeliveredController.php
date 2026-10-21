<?php

namespace App\Http\Controllers\Participant;

use App\Http\Controllers\Controller;
use App\Services\ParticipantFlow\Access;
use App\Services\Shared\Brand;
use Inertia\Inertia;

/** /a/{slug}/entregado: confirmation after the final submission. */
class DeliveredController extends Controller
{
    public function show(string $slug)
    {
        $a = Access::requirePublic($slug);

        return Inertia::render('Participant/Delivered', [
            'title' => $a->title,
            ...Brand::forParticipant($a->slug, $a->client_id),
        ]);
    }
}
