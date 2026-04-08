# 跟单任务编辑功能实现说明

## 功能概述

为跟单任务列表页面添加了编辑功能，并将扫尾盘的价格-时间限制配置从硬编码改为可在前端配置。

## 实现内容

### 1. 数据库变更

**新增迁移文件**: `2026_04_07_000001_add_price_time_config_to_pm_copy_tasks.php`

- 在 `pm_copy_tasks` 表添加字段 `tail_price_time_config` (JSON类型)
- 用于存储扫尾盘任务的价格-时间限制配置

**字段说明**:
```json
{
  "btc/usd": {
    "200": 180,  // 价格变化 >= 200 时，剩余时间 <= 180秒触发
    "100": 120,
    "50": 80,
    "40": 60,
    "35": 50,
    "30": 30
  },
  "eth/usd": {
    "200": 180,
    "100": 120,
    "30": 60,
    "20": 30
  }
}
```

### 2. 后端修改

#### 2.1 Model 层 (`PmCopyTask.php`)
- 添加 `tail_price_time_config` 到 `$casts` 数组，自动转换为 array 类型

#### 2.2 Controller 层 (`CopyTaskController.php`)

**修改点**:
1. `index()` - 查询时包含 `tail_price_time_config` 字段
2. `storeTailSweep()` - 创建任务时接收并保存配置
3. `updateTailSweep()` - 更新任务时支持修改配置
4. 返回数据时包含 `tail_price_time_config` 字段

#### 2.3 Command 层 (`PmScanTailSweepCommand.php`)

**修改逻辑**:
```php
// 优先使用任务自定义配置，否则使用默认配置
$taskConfig = is_array($task->tail_price_time_config) ? $task->tail_price_time_config : [];
$defaultConfig = [
    'btc/usd' => [200 => 180, 100 => 120, 50 => 80, 40 => 60, 35 => 50, 30 => 30],
    'eth/usd' => [200 => 180, 100 => 120, 30 => 60, 20 => 30],
];

$symbolConfig = $taskConfig[$symbol] ?? $defaultConfig[$symbol] ?? null;
```

**兼容性**:
- 如果任务未配置，自动使用系统默认规则
- 保持向后兼容，不影响现有任务

### 3. 前端修改 (`CopyTasksPage.vue`)

#### 3.1 新增功能

**编辑对话框**:
- 点击任务卡片的"编辑"按钮打开编辑对话框
- 支持编辑所有任务参数
- 扫尾盘任务支持配置价格-时间规则

**价格-时间配置界面**:
- 动态添加/删除规则
- 每条规则包含：价格变化阈值 → 时间限制(秒)
- 未配置时显示提示"未配置时使用系统默认规则"

#### 3.2 数据结构

**前端表单**:
```typescript
editForm: {
  // Leader Copy 模式
  ratio_bps: 10000,
  min_usdc: 0,
  max_usdc: 0,

  // Tail Sweep 模式
  tail_order_usdc: 0,
  tail_trigger_amount: '200',
  tail_time_limit_seconds: 30,
  tail_loss_stop_count: 0,
  tail_price_time_config: [
    { price: 200, time: 180 },
    { price: 100, time: 120 }
  ]
}
```

**数据转换**:
前端数组格式 → 后端 JSON 格式
```javascript
// 前端
[{ price: 200, time: 180 }, { price: 100, time: 120 }]

// 转换为后端格式
{
  "btc/usd": {
    "200": 180,
    "100": 120
  }
}
```

#### 3.3 UI 改进

**任务卡片操作按钮**:
- 暂停/恢复任务 (原有)
- **编辑** (新增)
- 删除 (原有)

**编辑对话框布局**:
- Leader Copy: 比例、最小/最大金额
- Tail Sweep: 下单金额、触发阈值、时间限制、亏损停止、价格-时间配置

### 4. API 接口

**已有接口** (无需新增):
```
PUT /api/copy-tasks/{id}
```

**请求参数** (Tail Sweep 模式):
```json
{
  "tail_order_usdc": 1000000,
  "tail_trigger_amount": "200",
  "tail_time_limit_seconds": 30,
  "tail_loss_stop_count": 5,
  "tail_price_time_config": {
    "btc/usd": {
      "200": 180,
      "100": 120,
      "50": 80
    }
  }
}
```

**请求参数** (Leader Copy 模式):
```json
{
  "ratio_bps": 10000,
  "min_usdc": 1000000,
  "max_usdc": 10000000
}
```

## 使用说明

### 1. 运行数据库迁移

```bash
cd dapp-api
php artisan migrate
```

### 2. 前端使用

1. 进入跟单任务页面 `http://localhost:5173/copy-tasks`
2. 在任务列表中点击"编辑"按钮
3. 修改任务参数
4. 对于扫尾盘任务，可以点击"添加规则"配置价格-时间限制
5. 点击"确认"保存修改

### 3. 配置示例

**BTC/USD 扫尾盘任务**:
- 价格变化 200 → 剩余 180 秒触发
- 价格变化 100 → 剩余 120 秒触发
- 价格变化 50 → 剩余 80 秒触发

**ETH/USD 扫尾盘任务**:
- 价格变化 200 → 剩余 180 秒触发
- 价格变化 100 → 剩余 120 秒触发
- 价格变化 30 → 剩余 60 秒触发

## 技术要点

### 1. 向后兼容
- 未配置 `tail_price_time_config` 的任务自动使用默认规则
- 不影响现有任务的运行

### 2. 数据验证
- 后端验证价格-时间配置格式
- 前端限制输入类型为数字

### 3. 性能优化
- 配置存储为 JSON，查询高效
- 扫描命令优先读取任务配置，减少硬编码

### 4. 用户体验
- 编辑对话框支持动态添加/删除规则
- 未配置时显示友好提示
- 保存后立即刷新任务列表

## 文件清单

### 后端文件
- `dapp-api/database/migrations/2026_04_07_000001_add_price_time_config_to_pm_copy_tasks.php` (新增)
- `dapp-api/app/Models/Pm/PmCopyTask.php` (修改)
- `dapp-api/app/Http/Controllers/Api/CopyTaskController.php` (修改)
- `dapp-api/app/Console/Commands/PmScanTailSweepCommand.php` (修改)

### 前端文件
- `dapp-h5/src/pages/CopyTasksPage.vue` (修改)

## 测试建议

1. **创建任务测试**
   - 创建扫尾盘任务，配置自定义价格-时间规则
   - 验证配置是否正确保存

2. **编辑任务测试**
   - 编辑现有任务参数
   - 添加/删除价格-时间规则
   - 验证更新是否生效

3. **扫描命令测试**
   - 运行 `php artisan pm:scan-tail-sweep --once`
   - 验证自定义配置是否被正确读取
   - 验证未配置任务是否使用默认规则

4. **兼容性测试**
   - 验证旧任务(无配置)是否正常运行
   - 验证新任务(有配置)是否按配置触发

## 注意事项

1. 价格-时间配置按标的(symbol)分组，每个任务只能配置当前标的的规则
2. 配置为空时自动使用系统默认规则，不会影响任务运行
3. 编辑任务时不会重置亏损计数等运行时状态
4. 价格阈值和时间限制必须为正数
