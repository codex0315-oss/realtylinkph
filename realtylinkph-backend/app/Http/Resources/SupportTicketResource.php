<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Documents;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupportTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isAdmin = $request->user()?->isAdmin() ?? false;

        // AI messages after the team's first reply were sent while the team was away.
        $firstTeamReplyId = $this->relationLoaded('messages') ? $this->messages->firstWhere('sender_type', 'admin')?->id : null;

        return [
            'id'              => $this->id,
            'status'          => $this->status,
            'category'        => $this->category,
            'summary'         => $this->when($isAdmin, $this->summary),
            'suggested_reply' => $this->when($isAdmin, $this->suggested_reply),
            'rating'          => $this->rating,
            'escalated_at'    => $this->escalated_at?->toISOString(),
            'resolved_at'     => $this->resolved_at?->toISOString(),
            'resolved_by'     => $this->resolved_by,
            'last_message_at' => $this->last_message_at?->toISOString(),
            // Is any admin online now? Drives "Team online" / "Team away" and whether the AI stands in.
            'team_online'     => $this->when($this->relationLoaded('messages'), fn () => \App\Services\SupportService::teamOnline()),
            'created_at'      => $this->created_at?->toISOString(),
            'user'            => $this->when($isAdmin, fn () => UserResource::make($this->whenLoaded('user'))),
            // Unread for the viewer: the user's messages for an admin, everything else for the user.
            'unread'          => $this->when(isset($this->unread_count), fn () => (int) $this->unread_count),
            'last_message'    => $this->whenLoaded('lastMessage', fn () => $this->lastMessage ? [
                'sender_type' => $this->lastMessage->sender_type,
                'body'        => $this->lastMessage->body ?? ($this->lastMessage->attachments ? 'Sent a screenshot' : ''),
            ] : null),
            'messages'        => $this->whenLoaded('messages', fn () => $this->messages->map(fn ($m) => [
                'id'          => $m->id,
                'sender_type' => $m->sender_type,
                'sender_name' => $m->sender_type === 'admin' ? ($m->sender?->name ?? 'RealtyLink PH team') : null,
                'stand_in'    => $m->sender_type === 'ai' && $firstTeamReplyId !== null && $m->id > $firstTeamReplyId,
                'body'        => $m->body,
                'attachments' => array_values(array_filter(array_map(fn ($p) => Documents::url($p), $m->attachments ?? []))),
                'read_at'     => $m->read_at?->toISOString(),
                'created_at'  => $m->created_at?->toISOString(),
            ])->values()),
        ];
    }
}
