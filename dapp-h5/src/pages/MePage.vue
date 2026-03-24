<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { showFailToast, showSuccessToast } from 'vant'
import { useAppStore } from '../stores/app'
import http from '../api/http'

const store = useAppStore()
const privateKey = ref('')
const saving = ref(false)
const checkingAllowance = ref(false)
const approving = ref(false)

const hasWallet = computed(() => !!store.walletStatus?.has_wallet)
const allowanceInfo = computed(() => store.walletAllowanceStatus?.allowance ?? null)
const readinessInfo = computed(() => store.walletAllowanceStatus?.readiness ?? null)
const allowanceItems = computed(() => allowanceInfo.value?.allowances || [])
const allowanceLabel = computed(() => {
  if (!allowanceInfo.value) return '未检测'
  return allowanceInfo.value.is_approved ? '已授权' : '未授权'
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

const importWallet = async () => {
  if (!privateKey.value) {
    showFailToast('请输入私钥')
    return
  }
  saving.value = true
  try {
    await http.post('/wallet/import', { private_key: privateKey.value })
    privateKey.value = ''
    await store.fetchWalletStatus()
    store.walletAllowanceStatus = null
    showSuccessToast('托管钱包导入成功')
  } catch (error: any) {
    showFailToast(error.message || '导入失败')
  } finally {
    saving.value = false
  }
}

const checkAllowanceStatus = async () => {
  if (!hasWallet.value) {
    showFailToast('请先导入托管钱包')
    return
  }
  checkingAllowance.value = true
  try {
    await store.fetchWalletAllowanceStatus()
  } catch (error: any) {
    showFailToast(error.message || '检测授权状态失败')
  } finally {
    checkingAllowance.value = false
  }
}

const approveWallet = async () => {
  if (!hasWallet.value) {
    showFailToast('请先导入托管钱包')
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
      <div class="section-header">
        <h2 class="section-title">账户概览</h2>
        <span class="info-chip" :class="hasWallet ? 'info-chip--success' : 'info-chip--warning'">
          {{ hasWallet ? '已导入托管钱包' : '未导入托管钱包' }}
        </span>
      </div>
      <van-cell-group inset>
        <van-cell title="钱包地址" :label="store.me?.address || '-'" class="address-row" />
        <van-cell title="昵称" :value="store.me?.nickname || '-'" />
        <van-cell title="Signer 地址" :label="store.walletStatus?.wallet?.signer_address || '-'" class="address-row" />
        <van-cell title="Funder 地址" :label="store.walletStatus?.wallet?.funder_address || '-'" class="address-row" />
      </van-cell-group>
    </section>

    <section class="surface-card section-card section-card--danger">
      <div class="section-header section-header--stack">
        <div>
          <h2 class="section-title">导入托管私钥</h2>
          <p class="inline-note">该区域为敏感操作，仅用于导入托管钱包，不会改变原有接口行为。</p>
        </div>
        <span class="info-chip info-chip--danger">敏感操作</span>
      </div>
      <van-cell-group inset>
        <van-field v-model="privateKey" type="textarea" rows="4" placeholder="0x... 或 64位hex 私钥" />
      </van-cell-group>
      <van-button block type="danger" :loading="saving" @click="importWallet">导入托管钱包</van-button>
    </section>

    <section class="surface-card section-card">
      <div class="section-header">
        <div>
          <h2 class="section-title">授权管理</h2>
          <p class="inline-note">检查当前可用余额与 spender 授权状态，并发起一键授权。</p>
        </div>
        <span
          class="info-chip"
          :class="allowanceLabel === '已授权' ? 'info-chip--success' : allowanceLabel === '未授权' ? 'info-chip--warning' : 'info-chip--brand'"
        >
          {{ allowanceLabel }}
        </span>
      </div>
      <van-cell-group inset>
        <van-cell title="授权状态" :value="allowanceLabel" />
        <van-cell title="可用余额" :value="formattedAllowanceBalance" />
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

.section-card--danger {
  border-color: rgba(239, 68, 68, 0.12);
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.96), rgba(254, 242, 242, 0.92));
}

.section-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.section-header--stack {
  align-items: center;
}

.address-row :deep(.van-cell__label),
.address-row :deep(.van-cell__title) {
  word-break: break-all;
}
</style>
