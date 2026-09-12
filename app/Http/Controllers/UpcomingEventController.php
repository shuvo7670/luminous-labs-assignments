<?php

namespace App\Http\Controllers;

use App\Http\Resources\EventResource;
use App\Models\Event;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UpcomingEventController extends Controller
{
    /**
     * List the next ten published events that start after the current instant.
     */
    public function __invoke(): AnonymousResourceCollection
    {
        $events = Event::query()
            ->where('status', Event::STATUS_PUBLISHED)
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit(10)
            ->get();

        return EventResource::collection($events);
    }
}
