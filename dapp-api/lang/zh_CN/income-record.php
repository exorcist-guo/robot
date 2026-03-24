<?php 
return [
    'labels' => [
        'IncomeRecord' => 'IncomeRecord',
        'income-record' => 'IncomeRecord',
    ],
    'fields' => [
        'member_id' => '用户ID',
        'amount' => '收益金额',
        'type' => '收益类型: random_reward=随机奖励, team_reward=团队奖励, performance_reward=业绩奖励',
        'tx_hash' => '交易哈希',
        'contract_dynamic_id' => '合约动态记录ID',
        'performance_record_id' => '关联的业绩记录ID',
        'from_grab_id' => '来源抢红包记录ID',
        'from_address' => '发起地址',
        'block_number' => '区块号',
        'time_stamp' => '交易时间',
        'remark' => '备注',
    ],
    'options' => [
    ],
];
