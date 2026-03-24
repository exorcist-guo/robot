<script setup lang="ts">
import { ref } from 'vue'
import { showFailToast, showSuccessToast } from 'vant'
import http from '../api/http'
import { useRouter } from 'vue-router'

const router = useRouter()
const address = ref('')
const signature = ref('')
const nonce = ref('')
const message = ref('')
const loading = ref(false)

const getNonce = async () => {
  if (!address.value) {
    showFailToast('请输入钱包地址')
    return
  }
  loading.value = true
  try {
    const { data } = await http.post('/auth/nonce', { address: address.value })
    nonce.value = data.data.nonce
    message.value = data.data.message
    showSuccessToast('已生成 nonce')
  } catch (error: any) {
    showFailToast(error.message || '获取 nonce 失败')
  } finally {
    loading.value = false
  }
}

const login = async () => {
  if (!address.value || !nonce.value || !signature.value) {
    showFailToast('请填写地址、nonce、signature')
    return
  }
  loading.value = true
  try {
    const { data } = await http.post('/auth/login', {
      address: address.value,
      nonce: nonce.value,
      signature: signature.value,
    })
    localStorage.setItem('token', data.data.token)
    showSuccessToast('登录成功')
    router.replace('/home')
  } catch (error: any) {
    showFailToast(error.message || '登录失败')
  } finally {
    loading.value = false
  }
}

const connectAndSign = async () => {
  if (!window.ethereum) {
    showFailToast('未检测到 MetaMask')
    return
  }

  loading.value = true
  try {
    const accounts = await window.ethereum.request({ method: 'eth_requestAccounts' })
    if (!accounts?.length) {
      throw new Error('未获取到钱包地址')
    }
    address.value = accounts[0]

    const nonceRes = await http.post('/auth/nonce', { address: address.value })
    nonce.value = nonceRes.data.data.nonce
    message.value = nonceRes.data.data.message

    signature.value = await window.ethereum.request({
      method: 'personal_sign',
      params: [message.value, address.value],
    })

    const loginRes = await http.post('/auth/login', {
      address: address.value,
      nonce: nonce.value,
      signature: signature.value,
    })

    localStorage.setItem('token', loginRes.data.data.token)
    showSuccessToast('MetaMask 登录成功')
    router.replace('/home')
  } catch (error: any) {
    showFailToast(error.message || 'MetaMask 登录失败')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="app-shell app-shell--auth">
    <div class="auth-layout">
      <section class="auth-hero">
        <span class="page-eyebrow">Polymarket Automation</span>
        <h1 class="page-title">自动跟单工作台</h1>
        <p class="page-description">连接钱包、完成签名认证后，即可进入跟单与授权管理流程。</p>
        <div class="auth-chips">
          <span class="info-chip info-chip--brand">Web3 登录</span>
          <span class="info-chip">H5 控制台</span>
        </div>
      </section>

      <section class="auth-card glass-card">
        <div class="auth-card__head">
          <div>
            <div class="section-title">钱包认证</div>
            <p class="inline-note">支持 MetaMask 一键登录，也可先获取 nonce 后手动签名。</p>
          </div>
        </div>

        <van-cell-group inset>
          <van-field v-model="address" label="钱包地址" placeholder="0x..." />
          <van-field v-model="nonce" label="Nonce" placeholder="先获取 nonce" readonly />
          <van-field v-model="signature" label="签名" type="textarea" rows="4" placeholder="钱包签名结果" />
          <van-field v-model="message" label="签名消息" type="textarea" rows="4" readonly />
        </van-cell-group>

        <div class="actions-stack">
          <van-button block type="primary" :loading="loading" @click="connectAndSign">MetaMask 一键登录</van-button>
          <van-button block plain type="primary" :loading="loading" @click="getNonce">仅获取 Nonce</van-button>
          <van-button block type="success" :loading="loading" @click="login">手动签名登录</van-button>
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped>
.auth-layout {
  display: grid;
  gap: 18px;
  width: 100%;
}

.auth-hero {
  display: grid;
  gap: 10px;
}

.auth-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.auth-card {
  display: grid;
  gap: 16px;
  padding: 20px;
}

.auth-card__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}
</style>
