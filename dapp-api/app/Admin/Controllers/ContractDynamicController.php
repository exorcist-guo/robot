<?php

namespace App\Admin\Controllers;

use App\Models\ContractDynamic;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Http\Controllers\AdminController;
use Dcat\Admin\Layout\Content;

class ContractDynamicController extends AdminController
{
    /**
     * page index
     */
    public function index(Content $content)
    {
        return $content
            ->header('合约动态')
            ->description('交易记录')
            ->breadcrumb(['text' => '合约动态', 'url' => ''])
            ->body($this->grid());
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        return Grid::make(new ContractDynamic(), function (Grid $grid) {
            $grid->column('id', 'ID')->sortable();
            $grid->column('status', '状态')->using(ContractDynamic::STATUS_MAP)->label([
                1 => 'success',    // 处理完成
                2 => 'info',       // 未知操作
                3 => 'secondary',  // 无效记录
                4 => 'danger',     // 上链失败
                5 => 'warning',    // 处理中有报错
                6 => 'dark',       // 交易解析失败
            ]);
            $grid->column('chain_id', '链ID');
            $grid->column('contract_address', '合约地址')->display(function ($value) {
                return $value ? '<span class="text-truncate d-inline-block" style="max-width: 120px;">' . substr($value, 0, 10) . '...' . substr($value, -6) . '</span>' : '-';
            });
            $grid->column('block_number', '区块号');
            $grid->column('time_stamp', '时间戳')->display(function ($value) {
                return $value ? date('Y-m-d H:i:s', $value) : '-';
            });
            $grid->column('tx_hash', '交易哈希')->display(function ($value) {
                return $value ? '<span class="text-truncate d-inline-block" style="max-width: 100px;">' . substr($value, 0, 10) . '...' . substr($value, -6) . '</span>' : '-';
            });
            $grid->column('from_address', '发送方')->display(function ($value) {
                return $value ? substr($value, 0, 8) . '...' . substr($value, -4) : '-';
            });
            $grid->column('to_address', '接收方')->display(function ($value) {
                return $value ? substr($value, 0, 8) . '...' . substr($value, -4) : '-';
            });
            $grid->column('value', '金额')->display(function ($value) {
                $val = hexdec($value) / 1e18;
                return number_format($val, 6) . ' ETH';
            });
            $grid->column('gas', 'Gas');
            $grid->column('gas_price', 'Gas Price')->display(function ($value) {
                return $value ? number_format(hexdec($value) / 1e9, 2) . ' Gwei' : '-';
            });
            $grid->column('is_error', '是否错误')->display(function ($value) {
                return $value == 0 ? '<span class="badge badge-success">正常</span>' : '<span class="badge badge-danger">错误</span>';
            });
            $grid->column('txreceipt_status', '收据状态')->display(function ($value) {
                return $value == 1 ? '<span class="badge badge-success">成功</span>' : '<span class="badge badge-danger">失败</span>';
            });
            $grid->column('function_name', '函数名')->display(function ($value) {
                return $value ?: '-';
            });
            $grid->column('created_at', '创建时间');

            $grid->model()->orderBy('id', 'desc');

            $grid->actions(function (Grid\Displayers\Actions $actions) {
                $actions->disableDelete();
                $actions->disableEdit();
            });

            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('id', 'ID');
                $filter->equal('status', '状态')->select(ContractDynamic::STATUS_MAP);
                $filter->like('contract_address', '合约地址');
                $filter->like('tx_hash', '交易哈希');
                $filter->like('from_address', '发送方地址');
                $filter->like('to_address', '接收方地址');
                $filter->equal('block_number', '区块号');
                $filter->between('time_stamp', '时间戳')->datetime();
                $filter->equal('is_error', '是否错误')->select([
                    0 => '正常',
                    1 => '错误'
                ]);
                $filter->like('function_name', '函数名');
                $filter->between('created_at', '创建时间')->datetime();
            });

            $grid->disableCreateButton();
            $grid->disableBatchDelete();
        });
    }

    /**
     * Make a show builder.
     *
     * @param mixed $id
     *
     * @return Show
     */
    protected function detail($id)
    {
        return Show::make($id, new ContractDynamic(), function (Show $show) {
            $show->field('id', 'ID');
            $show->field('status', '状态')->using(ContractDynamic::STATUS_MAP);
            $show->field('chain_id', '链ID');
            $show->field('contract_address', '合约地址');
            $show->field('block_number', '区块号');
            $show->field('time_stamp', '时间戳')->as(function ($value) {
                return $value ? date('Y-m-d H:i:s', $value) : '-';
            });
            $show->field('tx_hash', '交易哈希');
            $show->field('block_hash', '区块哈希');
            $show->field('nonce', 'Nonce');
            $show->field('transaction_index', '交易索引');
            $show->field('from_address', '发送方地址');
            $show->field('to_address', '接收方地址');
            $show->field('value', '金额')->as(function ($value) {
                $val = hexdec($value) / 1e18;
                return number_format($val, 18) . ' ETH';
            });
            $show->field('gas', 'Gas限制')->as(function ($value) {
                return $value ? hexdec($value) : '-';
            });
            $show->field('gas_price', 'Gas价格')->as(function ($value) {
                return $value ? number_format(hexdec($value) / 1e9, 2) . ' Gwei' : '-';
            });
            $show->field('cumulative_gas_used', '累计Gas使用')->as(function ($value) {
                return $value ? hexdec($value) : '-';
            });
            $show->field('gas_used', '已使用Gas')->as(function ($value) {
                return $value ? hexdec($value) : '-';
            });
            $show->field('confirmations', '确认数');
            $show->field('is_error', '是否错误')->as(function ($value) {
                return $value == 0 ? '正常' : '错误';
            });
            $show->field('txreceipt_status', '收据状态')->as(function ($value) {
                return $value == 1 ? '成功' : '失败';
            });
            $show->field('input', '输入数据')->as(function ($value) {
                return $value ? substr($value, 0, 100) . '...' : '-';
            });
            $show->field('method_id', '方法ID');
            $show->field('function_name', '函数名');
            $show->field('created_at', '创建时间');
            $show->field('updated_at', '更新时间');

            $show->panel()
                ->tools(function ($tools) {
                    $tools->disableDelete();
                });
        });
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        return Form::make(new ContractDynamic(), function (Form $form) {
            $form->display('id', 'ID');
            $form->display('status', '状态')->value(function ($value) {
                return ContractDynamic::STATUS_MAP[$value] ?? $value;
            });
            $form->display('chain_id', '链ID');
            $form->display('contract_address', '合约地址');
            $form->display('block_number', '区块号');
            $form->display('time_stamp', '时间戳')->value(function ($value) {
                return $value ? date('Y-m-d H:i:s', $value) : '-';
            });
            $form->display('tx_hash', '交易哈希');
            $form->display('from_address', '发送方地址');
            $form->display('to_address', '接收方地址');
            $form->display('value', '金额');
            $form->display('gas', 'Gas');
            $form->display('gas_price', 'Gas Price');
            $form->display('gas_used', '已使用Gas');
            $form->display('confirmations', '确认数');
            $form->display('is_error', '是否错误');
            $form->display('txreceipt_status', '收据状态');
            $form->display('function_name', '函数名');
            $form->display('created_at', '创建时间');
            $form->display('updated_at', '更新时间');

            $form->disableDeleteButton();
            $form->disableCreatingCheck();
            $form->disableEditingCheck();
            $form->disableViewCheck();

            // 禁用所有字段编辑
            $form->disableSubmitButton();
        });
    }
}
