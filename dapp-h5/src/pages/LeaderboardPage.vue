<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { showFailToast } from 'vant'
import { useRouter } from 'vue-router'
import http from '../api/http'

type LeaderboardItem = {
  rank: string
  proxy_wallet: string
  username: string
  volume: string
  pnl: string
  profile_image: string
  x_username: string
  verified_badge: boolean
}

const router = useRouter()

const leaders = ref<LeaderboardItem[]>([])
const loading = ref(false)
const finished = ref(false)
const limit = ref(25)
const offset = ref(0)
const userName = ref('')
const category = ref('OVERALL')
const timePeriod = ref('MONTH')
const orderBy = ref('PNL')
const showTimePeriodMenu = ref(false)
const showCategoryMenu = ref(false)
const showOrderByMenu = ref(false)

const categoryLabels: Record<string, string> = {
  OVERALL: '全部分类',
  POLITICS: '政治',
  SPORTS: '体育',
  CRYPTO: '加密',
  CULTURE: '文化',
  MENTIONS: '热点',
  WEATHER: '天气',
  ECONOMICS: '经济',
  TECH: '科技',
  FINANCE: '金融',
}
const categoryOrder = ['OVERALL', 'POLITICS', 'SPORTS', 'CRYPTO', 'CULTURE', 'MENTIONS', 'WEATHER', 'ECONOMICS', 'TECH', 'FINANCE']

const timePeriodLabels: Record<string, string> = {
  DAY: '今日',
  WEEK: '本周',
  MONTH: '本月',
  ALL: '全部',
}
const timePeriodOrder = ['DAY', 'WEEK', 'MONTH', 'ALL']

const orderByLabels: Record<string, string> = {
  PNL: '盈亏',
  VOL: '成交额',
}

const formatMoney = (value: unknown) => {
  const numeric = Number(value)
  if (Number.isNaN(numeric)) return '$0'
  const sign = numeric > 0 ? '+' : numeric < 0 ? '-' : ''
  return `${sign}$${Math.abs(numeric).toLocaleString('en-US', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  })}`
}

const shortenAddress = (value: string) => {
  if (!value) return '匿名用户'
  if (value.length <= 12) return value
  return `${value.slice(0, 6)}...${value.slice(-4)}`
}

const buildAvatarStyle = (wallet: string, index: number) => {
  if (wallet) {
    const gradients = [
      'linear-gradient(135deg, #ff6a00, #ee0979)',
      'linear-gradient(135deg, #00c6ff, #0072ff)',
      'linear-gradient(135deg, #7f00ff, #00d2ff)',
      'linear-gradient(135deg, #11998e, #38ef7d)',
      'linear-gradient(135deg, #fc466b, #3f5efb)',
    ]
    return { background: gradients[index % gradients.length] }
  }
  return {}
}

const leaderRows = computed(() => leaders.value.map((item, index) => ({
  ...item,
  rankText: item.rank || String(offset.value + index + 1),
  name: item.username || shortenAddress(item.proxy_wallet),
  meta: item.x_username ? `@${item.x_username}` : shortenAddress(item.proxy_wallet),
  avatarStyle: buildAvatarStyle(item.proxy_wallet, index),
  sideValue: orderBy.value === 'VOL' ? formatMoney(item.volume) : formatMoney(item.pnl),
  sideLabel: orderBy.value === 'VOL' ? '成交额' : '盈亏',
})))

const resetList = () => {
  leaders.value = []
  offset.value = 0
  finished.value = false
}

const loadLeaders = async (reset = false) => {
  if (loading.value || (!reset && finished.value)) return

  if (reset) {
    resetList()
  }

  loading.value = true
  try {
    const currentOffset = reset ? 0 : offset.value
    const { data } = await http.get('/markets/leaderboard', {
      params: {
        category: category.value,
        timePeriod: timePeriod.value,
        orderBy: orderBy.value,
        limit: limit.value,
        offset: currentOffset,
        userName: userName.value.trim() || undefined,
      },
    })

    const payload = data?.data || {}
    const list = Array.isArray(payload.list) ? payload.list : []
    const current = reset ? [] : leaders.value
    const merged = [...current, ...list]
    const unique = merged.filter((item, index, array) => array.findIndex(row => row.proxy_wallet === item.proxy_wallet) === index)
    leaders.value = unique
    offset.value = unique.length
    finished.value = list.length < limit.value || (list.length > 0 && unique.length === current.length)
  } catch (error: any) {
    showFailToast(error?.message || '加载排行榜失败')
  } finally {
    loading.value = false
  }
}

const searchLeaders = async () => {
  await loadLeaders(true)
}

const chooseTimePeriod = () => {
  showTimePeriodMenu.value = !showTimePeriodMenu.value
  if (showTimePeriodMenu.value) {
    showCategoryMenu.value = false
    showOrderByMenu.value = false
  }
}

const chooseCategory = () => {
  showCategoryMenu.value = !showCategoryMenu.value
  if (showCategoryMenu.value) {
    showTimePeriodMenu.value = false
    showOrderByMenu.value = false
  }
}

const chooseOrderBy = () => {
  showOrderByMenu.value = !showOrderByMenu.value
  if (showOrderByMenu.value) {
    showTimePeriodMenu.value = false
    showCategoryMenu.value = false
  }
}

const selectTimePeriod = async (value: string) => {
  if (timePeriod.value === value) {
    showTimePeriodMenu.value = false
    return
  }
  timePeriod.value = value
  showTimePeriodMenu.value = false
  await loadLeaders(true)
}

const selectCategory = async (value: string) => {
  if (category.value === value) {
    showCategoryMenu.value = false
    return
  }
  category.value = value
  showCategoryMenu.value = false
  await loadLeaders(true)
}

const selectOrderBy = async (value: string) => {
  if (orderBy.value === value) {
    showOrderByMenu.value = false
    return
  }
  orderBy.value = value
  showOrderByMenu.value = false
  await loadLeaders(true)
}

const openProfileStats = (proxyWallet: string) => {
  if (!proxyWallet) return
  router.push(`/profile-stats/${proxyWallet}`)
}

onMounted(() => {
  loadLeaders(true)
})
</script>

<template>
  <div class="app-shell leaderboard-shell">
    <section class="page-head leaderboard-head">
      <h1 class="leaderboard-title">排行榜</h1>
      <p class="page-description">实时展示 Polymarket 榜单用户，按时间、分类和排序口径切换浏览。</p>
    </section>

    <section class="leaderboard-filters glass-card">
      <div class="filter-menu-wrap">
        <button type="button" class="filter-pill" @click="chooseTimePeriod">
          {{ timePeriodLabels[timePeriod] }}
          <van-icon :name="showTimePeriodMenu ? 'arrow-up' : 'arrow-down'" />
        </button>
        <div v-if="showTimePeriodMenu" class="filter-menu surface-card">
          <button
            v-for="value in timePeriodOrder"
            :key="value"
            type="button"
            class="filter-menu__item"
            :class="{ 'filter-menu__item--active': timePeriod === value }"
            @click="selectTimePeriod(value)"
          >
            {{ timePeriodLabels[value] }}
          </button>
        </div>
      </div>
      <div class="filter-menu-wrap filter-menu-wrap--right">
        <button type="button" class="filter-pill filter-pill--ghost" @click="chooseCategory">
          {{ categoryLabels[category] }}
          <van-icon :name="showCategoryMenu ? 'arrow-up' : 'arrow-down'" />
        </button>
        <div v-if="showCategoryMenu" class="filter-menu surface-card filter-menu--wide">
          <button
            v-for="value in categoryOrder"
            :key="value"
            type="button"
            class="filter-menu__item"
            :class="{ 'filter-menu__item--active': category === value }"
            @click="selectCategory(value)"
          >
            {{ categoryLabels[value] }}
          </button>
        </div>
      </div>
    </section>

    <section class="leaderboard-toolbar surface-card">
      <div class="leaderboard-search">
        <van-icon name="search" class="leaderboard-search__icon" />
        <input v-model="userName" type="text" class="leaderboard-search__input" placeholder="搜索用户名" @keyup.enter="searchLeaders" />
      </div>
      <div class="filter-menu-wrap filter-menu-wrap--right">
        <button type="button" class="leaderboard-sort" @click="chooseOrderBy">
          {{ orderByLabels[orderBy] }}
          <van-icon :name="showOrderByMenu ? 'arrow-up' : 'arrow-down'" />
        </button>
        <div v-if="showOrderByMenu" class="filter-menu surface-card filter-menu--compact">
          <button
            v-for="value in ['PNL', 'VOL']"
            :key="value"
            type="button"
            class="filter-menu__item"
            :class="{ 'filter-menu__item--active': orderBy === value }"
            @click="selectOrderBy(value)"
          >
            {{ orderByLabels[value as keyof typeof orderByLabels] }}
          </button>
        </div>
      </div>
    </section>

    <van-list :loading="loading" :finished="finished" finished-text="没有更多榜单成员了" @load="loadLeaders(false)">
      <section class="leaderboard-list surface-card">
        <article v-for="item in leaderRows" :key="item.proxy_wallet" class="leaderboard-item" @click="openProfileStats(item.proxy_wallet)">
          <div class="leaderboard-rank">{{ item.rankText }}</div>
          <div class="leaderboard-avatar" :style="item.avatarStyle">
            <img v-if="item.profile_image" :src="item.profile_image" :alt="item.name" class="leaderboard-avatar__image" />
            <span v-else>{{ item.name.slice(0, 1).toUpperCase() }}</span>
            <span v-if="item.verified_badge" class="leaderboard-verified">✓</span>
          </div>
          <div class="leaderboard-copy">
            <div class="leaderboard-name">{{ item.name }}</div>
            <div class="leaderboard-meta">{{ item.meta }}</div>
          </div>
          <div class="leaderboard-side">
            <div class="leaderboard-side__value">{{ item.sideValue }}</div>
            <!-- <span class="leaderboard-badge">{{ item.sideLabel }}</span> -->
          </div>
        </article>
      </section>
    </van-list>

    <div v-if="!leaderRows.length && !loading" class="surface-card empty-state">当前条件下暂无排行榜成员。</div>
  </div>
</template>

<style scoped>
.leaderboard-shell {
  padding-top: 20px;
}

.leaderboard-head {
  margin-bottom: 22px;
}

.leaderboard-title {
  margin: 0;
  font-size: 34px;
  line-height: 1.05;
  font-weight: 900;
  letter-spacing: -0.06em;
  color: #111827;
}

.leaderboard-filters {
  position: relative;
  z-index: 30;
  display: flex;
  justify-content: space-between;
  gap: 12px;
  padding: 14px;
  margin-bottom: 14px;
  overflow: visible;
}

.filter-menu-wrap {
  position: relative;
}

.filter-menu-wrap--right {
  margin-left: auto;
}

.filter-pill {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-width: 108px;
  border: 0;
  border-radius: 18px;
  padding: 14px 18px;
  background: #ffffff;
  color: #111827;
  font-size: 15px;
  font-weight: 700;
  box-shadow: inset 0 0 0 1px rgba(203, 213, 225, 0.9);
}

.filter-pill--ghost {
  background: rgba(255, 255, 255, 0.82);
}

.filter-menu {
  position: absolute;
  left: 0;
  top: calc(100% + 6px);
  min-width: 140px;
  padding: 8px;
  border-radius: 20px;
  box-shadow: 0 20px 38px rgba(15, 23, 42, 0.12);
  z-index: 200;
}

.filter-menu--wide {
  min-width: 160px;
}

.filter-menu--compact {
  right: 0;
  left: auto;
  min-width: 120px;
}

.filter-menu__item {
  display: block;
  width: 100%;
  border: 0;
  border-radius: 14px;
  padding: 12px 14px;
  background: transparent;
  color: #475569;
  font-size: 14px;
  font-weight: 700;
}

.filter-menu__item--active {
  background: #f3f4f6;
  color: #111827;
}

.leaderboard-toolbar {
  position: relative;
  z-index: 40;
  overflow: visible;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 16px 14px;
  margin-bottom: 14px;
}

.leaderboard-search {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
  flex: 1 1 auto;
}

.leaderboard-search__icon {
  color: #94a3b8;
  font-size: 20px;
}

.leaderboard-search__input {
  width: 100%;
  border: 0;
  outline: none;
  background: transparent;
  color: #111827;
  font-size: 16px;
  font-weight: 600;
}

.leaderboard-search__input::placeholder {
  color: #cbd5e1;
}

.leaderboard-sort {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  border: 0;
  background: transparent;
  color: #111827;
  font-size: 16px;
  font-weight: 700;
}

.leaderboard-list {
  position: relative;
  z-index: 1;
  overflow: hidden;
}

.leaderboard-item {
  display: grid;
  grid-template-columns: 28px 54px minmax(0, 1fr) auto;
  align-items: center;
  gap: 14px;
  padding: 18px 0;
  margin: 0 16px;
}

.leaderboard-item + .leaderboard-item {
  border-top: 1px solid rgba(226, 232, 240, 0.9);
}

.leaderboard-rank {
  color: #64748b;
  font-size: 16px;
  font-weight: 700;
}

.leaderboard-avatar {
  position: relative;
  width: 48px;
  height: 48px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  overflow: hidden;
  color: #ffffff;
  font-size: 18px;
  font-weight: 800;
  box-shadow: 0 12px 26px rgba(15, 23, 42, 0.12);
}

.leaderboard-avatar__image {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.leaderboard-verified {
  position: absolute;
  left: -2px;
  bottom: -2px;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  background: #f59e0b;
  color: #ffffff;
  font-size: 11px;
  font-weight: 900;
  box-shadow: 0 6px 12px rgba(245, 158, 11, 0.25);
}

.leaderboard-copy {
  min-width: 0;
}

.leaderboard-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: #111827;
  font-size: 18px;
  font-weight: 700;
  letter-spacing: -0.03em;
}

.leaderboard-meta {
  margin-top: 4px;
  color: #94a3b8;
  font-size: 12px;
  font-weight: 600;
}

.leaderboard-side {
  text-align: right;
}

.leaderboard-side__value {
  color: #111827;
  font-size: 16px;
  font-weight: 800;
  letter-spacing: -0.03em;
}

.leaderboard-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  margin-top: 6px;
  min-height: 24px;
  padding: 0 10px;
  border-radius: 999px;
  background: rgba(15, 118, 110, 0.1);
  color: #0f766e;
  font-size: 12px;
  font-weight: 700;
}
</style>
