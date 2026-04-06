<?php

namespace App\Http\Middleware;

use App\Models\Member;
use App\Traits\ApiResponseTrait;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSgin
{
    use ApiResponseTrait;
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $address = $request->header('address', '');


        if ($address) {
            // 验证地址格式
            if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $address)) {
                return $this->error('无效的钱包地址');
            }

            // 转为小写统一存储
            $address = strtolower($address);

            // 查询用户是否存在
            $member = Member::where('address', $address)->first();

            if (!$member) {
                // 用户不存在，创建新用户
                $member = Member::createMember($address);
            }

            // 将用户信息存入请求，供后续使用
            $request->merge(['current_member' => $member]);
            $request->attributes->set('member', $member);
        } else {
            return $this->error('缺少 address 参数');
        }

        return $next($request);
    }
}
