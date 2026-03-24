<?php

namespace App\Http\Controllers;

use App\Models\IncomeRecord;
use App\Models\Member;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;

class RedController extends Controller
{
    use ApiResponseTrait;

    public function home(Request $request)
    {
        $member = $request->attributes->get('member');

        if (!$member) {
            return $this->error('用户不存在');
        }

        $levels = Member::LEVEL;
        $next_level = $member->level + 1;

        // 检查下一等级是否存在
        if (isset($levels[$next_level])) {
            // 下一等级存在，计算升级进度
            $level_performance = $levels[$next_level]['performance'];
            $level_speed = bcmul(bcdiv($member->performance, $level_performance, 8), '100', 2);
            $poor_performance = bcsub($level_performance, $member->performance, 0);

            // 限制进度不超过 99.99%
            if ($level_speed >= 100) {
                $level_speed = '99.99';
                $poor_performance = '0';
            }
        } else {
            // 已达到最高等级
            $level_speed = '100';
            $poor_performance = '0';
            $next_level = $member->level; // 保持当前等级
        }

        return $this->success('获取成功', [
            'address' => $member->address,
            'level' => $member->level,
            'performance' => (string) $member->performance,
            'poor_performance' => (int) $poor_performance,
            'next_level' => $next_level,
            'level_speed' => (float) $level_speed,
            'total_earnings' => bcadd($member->total_earnings,'0'),
        ]);
    }


    //社区
    public function community(Request $request){
        $member = $request->attributes->get('member');

        if (!$member) {
            return $this->error('用户不存在');
        }

        $levels = Member::LEVEL;
        $next_level = $member->level + 1;

        // 检查下一等级是否存在
        if (isset($levels[$next_level])) {
            // 下一等级存在，计算升级进度
            $level_performance = $levels[$next_level]['performance'];
            $level_speed = bcmul(bcdiv($member->performance, $level_performance, 8), '100', 2);
            $poor_performance = bcsub($level_performance, $member->performance, 0);

            // 限制进度不超过 99.99%
            if ($level_speed >= 100) {
                $level_speed = '99.99';
                $poor_performance = '0';
            }
        } else {
            // 已达到最高等级
            $level_speed = '100';
            $poor_performance = '0';
            $next_level = $member->level; // 保持当前等级
        }


        //社区人数
        $user = $member;
        if($user->path){
            $path = $user->path . $user->id.'/';
        }else{
            $path = '/'.$user->id.'/';
        }
        $community_num = Member::where('path', 'like', "{$path}%")->count();


        return $this->success('获取成功', [
            'address' => $member->address,
            'level' => $member->level,
            'performance' => (string) $member->performance,
            'poor_performance' => (int) $poor_performance,
            'next_level' => $next_level,
            'level_speed' => (float) $level_speed,
            'community_num' => $community_num,
            'invitation_url' => config('app.h5_url') . '?invite=' . $member->address,

        ]);

    }

    public function team(Request $request){
        $user = $request->attributes->get('member');
        $page = $request->input('page', 1);
        $limit =  $request->input('limit', 15);

        $type = $request->input('type', 0);
        if($user->path){
            $path = $user->path . $user->id.'/';
        }else{
            $path = '/'.$user->id.'/';
        }
        $user_deep = $user->deep?:0;


        $query = Member::where('path', 'like', "{$path}%")
        ;
        $count = $query->count();
        if($type == 5 || $type == 6){
            $query = $query->orderByDesc('tz_hk');
        }else{
            $query->orderByDesc('id');
        }
        $list = $query ->forPage($page, $limit)
            ->get()
            ->map(function(Member $user)use($user_deep){
                $data =  $user->only(['address','level','performance','created_at']);
                $data['c_deep'] = $user->deep - $user_deep;
                $data['performance'] = bcadd($user->performance,'0');
                $data['created_at'] = date('Y-m-d H:i', $user->created_at->timestamp);
                $data['address'] = substr($user->address, 0, 7) . '...' . substr($user->address, -7);
                return $data;
            })
        ;
        $data = [
            'count' => $count,
            'list' => $list,
            'path' => $path,
        ];
        return $this->success('success', $data);
    }

    public function rule(){
        $rules = [
            ['title'=>'抢红包','content'=>'每次消耗10 USDT，随机获得1-20 USDT'],
            ['title'=>'时间延长','content'=>'每次抢红包延长30秒，上限30分钟'],
            ['title'=>'超级大奖','content'=>'最后10名参与者获得50%奖池'],
            ['title'=>'直推奖励','content'=>'好友参与获10%'],
            ['title'=>'团队奖励','content'=>'L1-L15等级团队业绩达标享受1%-15%奖励'],
            ['title'=>'资金分配','content'=>'50%超级大奖池 + 20%随机池 + 10%直推 + 15%团队 + 5%Gas费=100%分配'],
            ['title'=>'幸运奖池','content'=>'倒计时未结束随机开出超级奖池10%红包'],
            ['title'=>'奖池分配','content'=>'总奖池80%本轮分配、10%进入下轮随机池、10%进入下轮超级奖池']
        ];

        $data = [
            'rules' => $rules,
            'levels' => Member::LEVEL,
        ];

        return $this->success('success', $data);
    }


    public function incomeRecords(Request $request){
        $user = $request->attributes->get('member');
        $page = $request->input('page', 1);
        $limit =  $request->input('limit', 15);
        $type = $request->input('type', '');

        $query = IncomeRecord::where('member_id', $user->id);

        // 按类型筛选
        if (!empty($type)) {
            $query->where('type', $type);
        }

        $count = $query->count();
        $list = $query->orderByDesc('id')
            ->forPage($page, $limit)
            ->get()
            ->map(function(IncomeRecord $record)use($user){
                $typeMap = IncomeRecord::TYPE_MAP;
                $typeName = $typeMap[$record->type] ?? '未知';
                return [

                    'amount' => (string) $record->amount,
                    'member_address' => substr($user->address, 0, 7) . '...' . substr($user->address, -7),
                    'type' => $record->type,
                    'type_name' => $typeName,
//                    'remark' => $record->remark,
                    'time_stamp' => $record->time_stamp ? $record->time_stamp->format('Y-m-d H:i:s') : '',
//                    'created_at' => $record->created_at ? $record->created_at->format('Y-m-d H:i:s') : '',
                ];
            });

        $data = [
            'count' => $count,
            'list' => $list,
        ];
        return $this->success('获取成功', $data);
    }

    public function participateRecords(Request $request){

        $page = $request->input('page', 1);
        $limit =  $request->input('limit', 15);
        $query = IncomeRecord::where('type', 'random_reward');

        $count = $query->count();
        $list = $query->orderByDesc('id')
            ->forPage($page, $limit)
            ->get()
            ->map(function(IncomeRecord $record){
                return [

                    'amount' => (string) $record->amount,
                    'member_address' => $record->member->address?substr($record->member->address, 0, 7) . '...' . substr($record->member->address, -7):'',
                    'type' => $record->type,
                    'time_stamp' => $record->time_stamp ? $record->time_stamp->format('Y-m-d H:i:s') : '',
                ];
            });

        $data = [
            'count' => $count,
            'list' => $list,
        ];
        return $this->success('获取成功', $data);
    }


}
