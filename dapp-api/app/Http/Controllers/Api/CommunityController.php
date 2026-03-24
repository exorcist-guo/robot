<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pm\PmMember;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;

class CommunityController extends Controller
{
    use ApiResponseTrait;

    private function currentMember(Request $request): PmMember
    {
        /** @var PmMember $user */
        $user = $request->user();
        return $user;
    }

    public function summary(Request $request)
    {
        $member = $this->currentMember($request);

        $pathPrefix = $member->path === '/' ? '/' . $member->id . '/' : $member->path . $member->id . '/';
        $inviteCount = PmMember::where('inviter_id', $member->id)->count();
        $teamCount = PmMember::where('path', 'like', $pathPrefix . '%')->count();

        return $this->success('ok', [
            'invite_count' => $inviteCount,
            'team_count' => $teamCount,
            'invite_url' => rtrim((string) config('app.h5_url'), '/') . '?invite=' . $member->address,
        ]);
    }

    public function records(Request $request)
    {
        $member = $this->currentMember($request);
        $page = max(1, (int) $request->query('page', 1));
        $limit = min(50, max(1, (int) $request->query('limit', 20)));

        $query = PmMember::where('inviter_id', $member->id);
        $count = (clone $query)->count();

        $list = $query->orderByDesc('id')
            ->forPage($page, $limit)
            ->get()
            ->map(fn (PmMember $m) => [
                'id' => $m->id,
                'address' => $m->address,
                'nickname' => $m->nickname,
                'avatar_url' => $m->avatar_url,
                'created_at' => $m->created_at?->toDateTimeString(),
            ]);

        return $this->success('ok', [
            'count' => $count,
            'list' => $list,
        ]);
    }
}
