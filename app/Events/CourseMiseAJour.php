<?php

namespace App\Events;

use App\Http\Resources\CourseResource;
use App\Models\Course;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Diffusé à chaque évolution d'une course.
 *
 * C'est ce qui permet au client de suivre l'arrivée de son chauffeur sur la
 * carte sans interroger le serveur en boucle — un souci réel sur une 3G
 * facturée au volume.
 */
class CourseMiseAJour implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Course $course) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('course.'.$this->course->id);
    }

    public function broadcastAs(): string
    {
        return 'course.maj';
    }

    public function broadcastWith(): array
    {
        $this->course->loadMissing(['chauffeur.utilisateur', 'chauffeur.dernierePosition', 'client']);

        return [
            'course' => (new CourseResource($this->course))->resolve(),
        ];
    }
}
