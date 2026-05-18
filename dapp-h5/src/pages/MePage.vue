<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { showFailToast, showSuccessToast } from 'vant'
import { useAppStore } from '../stores/app'
import http from '../api/http'

const store = useAppStore()
const checkingAllowance = ref(false)
const approving = ref(false)
const accountExpanded = ref(false)
const allowanceExpanded = ref(false)

const toggleAccount = () => {
  accountExpanded.value = !accountExpanded.value
}

const toggleAllowance = () => {
  allowanceExpanded.value = !allowanceExpanded.value
}

const hasWallet = computed(() => !!store.walletStatus?.has_wallet)
const allowanceInfo = computed(() => store.walletAllowanceStatus?.allowance ?? null)
const readinessInfo = computed(() => store.walletAllowanceStatus?.readiness ?? null)
const allowanceItems = computed(() => allowanceInfo.value?.allowances || [])
const tradingWalletAddress = computed(() => store.walletStatus?.wallet?.funder_address || store.walletStatus?.wallet?.signer_address || '-')
const allowanceLabel = computed(() => {
  if (!allowanceInfo.value) return '未检测'
  return allowanceInfo.value.is_approved ? 'BUY 全量授权已完成' : 'BUY 全量授权未完成'
})
const formattedAllowanceBalance = computed(() => {
  const raw = allowanceInfo.value?.balance
  if (raw === null || raw === undefined || raw === '') return '-'

  const value = Number(raw)
  if (Number.isNaN(value)) return '-'

  return `${(value / 1_000_000).toLocaleString('zh-CN', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 6,
  })} USDC.e`
})

onMounted(async () => {
  await store.fetchMe().catch(() => null)
  const walletStatus = await store.fetchWalletStatus().catch(() => null)
  if (walletStatus?.has_wallet) {
    await store.fetchWalletAllowanceStatus().catch(() => null)
  }
})

const logout = () => {
  localStorage.removeItem('token')
  location.href = '/login'
}

const checkAllowanceStatus = async () => {
  if (!hasWallet.value) {
    showFailToast('PM 托管钱包不存在，请重新登录后重试')
    return
  }
  checkingAllowance.value = true
  try {
    await store.fetchWalletAllowanceStatus({ refresh: 1 })
  } catch (error: any) {
    showFailToast(error.message || '检测授权状态失败')
  } finally {
    checkingAllowance.value = false
  }
}

const approveWallet = async () => {
  if (!hasWallet.value) {
    showFailToast('PM 托管钱包不存在，请重新登录后重试')
    return
  }
  approving.value = true
  try {
    const { data } = await http.post('/wallet/approve')
    showSuccessToast(data.msg || '授权交易已提交')
    await Promise.all([
      store.fetchWalletStatus().catch(() => null),
      store.fetchWalletAllowanceStatus().catch(() => null),
    ])
  } catch (error: any) {
    showFailToast(error.message || '授权失败')
  } finally {
    approving.value = false
  }
}
</script>

<template>
  <div class="app-shell">
    <section class="page-head">
      <span class="page-eyebrow">Account Center</span>
      <h1 class="page-title">我的</h1>
      <p class="page-description">集中查看账户资料、托管钱包状态与授权信息。</p>
    </section>

    <section class="surface-card section-card">
      <button type="button" class="section-toggle" @click="toggleAccount">
        <div class="section-header">
          <h2 class="section-title">账户概览</h2>
          <div class="section-toggle__right">
            <span class="info-chip" :class="hasWallet ? 'info-chip--success' : 'info-chip--warning'">
              {{ hasWallet ? 'PM 托管钱包已创建' : 'PM 托管钱包未创建' }}
            </span>
            <span class="section-toggle__icon">{{ accountExpanded ? '−' : '+' }}</span>
          </div>
        </div>
      </button>
      <template v-if="accountExpanded">
        <van-cell-group inset>
          <van-cell title="钱包地址" :label="store.me?.address || '-'" class="address-row" />
          <van-cell title="昵称" :value="store.me?.nickname || '-'" />
          <van-cell title="Signer 地址" :label="store.walletStatus?.wallet?.signer_address || '-'" class="address-row" />
          <van-cell title="Funder 地址" :label="store.walletStatus?.wallet?.funder_address || '-'" class="address-row" />
        </van-cell-group>
      </template>
    </section>

    <section class="surface-card section-card">
      <button type="button" class="section-toggle" @click="toggleAllowance">
        <div class="section-header">
          <div>
            <h2 class="section-title">BUY 授权管理</h2>
            <p class="inline-note">当前页面检查的是托管交易钱包对 USDC.e 的 BUY 授权状态；只有以下 spender 全部已授权时，状态才会显示完成。</p>
          </div>
          <div class="section-toggle__right section-toggle__right--top">
            <span
              class="info-chip"
              :class="allowanceLabel === 'BUY 全量授权已完成' ? 'info-chip--success' : allowanceLabel === 'BUY 全量授权未完成' ? 'info-chip--warning' : 'info-chip--brand'"
            >
              {{ allowanceLabel }}
            </span>
            <span class="section-toggle__icon">{{ allowanceExpanded ? '−' : '+' }}</span>
          </div>
        </div>
      </button>
      <template v-if="allowanceExpanded">
        <van-cell-group inset>
          <van-cell title="BUY 授权状态" :value="allowanceLabel" />
          <van-cell title="检测地址" :label="tradingWalletAddress" class="address-row" />
          <van-cell title="USDC.e 可用余额" :value="formattedAllowanceBalance" />
          <van-cell title="失败代码" :value="readinessInfo?.failure_code || '-'" />
          <van-cell title="是否就绪" :value="readinessInfo?.is_ready ? '是' : '否'" />
        </van-cell-group>
        <van-cell-group v-if="allowanceItems.length" inset>
          <van-cell
            v-for="item in allowanceItems"
            :key="item.spender"
            :title="item.spender"
            :label="item.allowance"
            :value="item.is_approved ? '已授权' : '未授权'"
            class="address-row"
          />
        </van-cell-group>
        <div class="actions-stack">
          <van-button block plain type="primary" :loading="checkingAllowance" :disabled="approving" @click="checkAllowanceStatus">
            检测授权状态
          </van-button>
          <van-button block type="primary" :loading="approving" :disabled="checkingAllowance" @click="approveWallet">
            一键授权
          </van-button>
        </div>
      </template>
    </section>

    <section class="actions-stack">
      <van-button block type="primary" to="/copy-tasks">跟单任务</van-button>
      <van-button block type="warning" @click="logout">退出登录</van-button>
    </section>
  </div>
</template>

<style scoped>
.section-card {
  display: grid;
  gap: 14px;
  padding: 18px;
  margin-bottom: 18px;
}

.section-toggle {
  border: 0;
  padding: 0;
  background: transparent;
  text-align: left;
}

.section-toggle__right {
  display: flex;
  align-items: center;
  gap: 10px;
  flex: 0 0 auto;
}

.section-toggle__right--top {
  align-items: flex-start;
}

.section-toggle__icon {
  color: #64748b;
  font-size: 20px;
  line-height: 1;
  font-weight: 700;
}

.section-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.address-row :deep(.van-cell__label),
.address-row :deep(.van-cell__title) {
  word-break: break-all;
}
</style>
