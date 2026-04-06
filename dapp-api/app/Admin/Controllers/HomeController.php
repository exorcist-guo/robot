<?php

namespace App\Admin\Controllers;

use App\Models\Member;
use App\Models\PerformanceRecord;
use App\Http\Controllers\Controller;
use App\Services\BnbService;
use Dcat\Admin\Layout\Content;
use Dcat\Admin\Layout\Row;
use Dcat\Admin\Layout\Column;
use Illuminate\Support\Facades\DB;

class HomeController extends Controller
{
    public function index(Content $content)
    {
        // 获取统计数据
        $totalMembers = Member::count();
        $newMembersToday = Member::whereDate('created_at', today())->count();
        $totalParticipations = PerformanceRecord::count();
        $totalAmount = PerformanceRecord::sum('amount');



        return $content
            ->title('数据统计')
            ->description('实时数据概览')
            ->row(function (Row $row) use ($totalMembers, $newMembersToday, $totalParticipations, $totalAmount) {
                $row->column(3, $this->renderCard('总用户数', $totalMembers, 'feather icon-users', 'primary'));
                $row->column(3, $this->renderCard('今日新增', $newMembersToday, 'feather icon-user-plus', 'success'));
                $row->column(3, $this->renderCard('总参与数', $totalParticipations, 'feather icon-activity', 'info'));
                $row->column(3, $this->renderCard('总参与金额', number_format($totalAmount, 2) . ' USDT', 'feather icon-dollar-sign', 'warning'));


            });
    }

    private function renderCard($title, $content, $icon, $color = 'primary')
    {
        $colors = [
            'primary' => 'primary',
            'success' => 'success',
            'info' => 'info',
            'warning' => 'warning',
            'danger' => 'danger',
        ];
        $bgColor = $colors[$color] ?? 'primary';

        return <<<HTML
<div class="card bg-{$bgColor}">
    <div class="card-body">
        <div class="d-flex">
            <div class="text-white">
                <h3 class="mb-0">{$content}</h3>
                <span class="small">{$title}</span>
            </div>
            <div class="ml-auto">
                <i class="{$icon} f-24 text-white-50"></i>
            </div>
        </div>
    </div>
</div>
HTML;
    }
}
