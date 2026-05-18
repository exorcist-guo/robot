<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { showConfirmDialog, showToast } from 'vant'
import http from '../api/http'

// 页面分段与持仓状态
type Segment = 'holding' | 'records'
type PositionStatus = 'active' | 'closed'

// 顶部汇总数据
type SummaryState = {
  holdingValue: string
  bestProfit: string
  predictions: string
  pnlLabel: string
  pnlValue: string
  pnlPeriod: string
}

type PagedState = {
  loading: boolean
  finished: boolean
  initialized: boolean
  offset: number
  limit: number
}

// 生效中持仓卡片
type PositionItem = {
  key: string
  tokenId: string
  marketId: string
  title: string
  subtitle: string
  badge: string
  shares: string
  value: string
  pnl: string
  positive: boolean
  icon: string
  logo: string
  tint: string
}

// 已结束持仓卡片
type ClosedPositionItem = {
  key: string
  title: string
  subtitle: string
  openBadge: string
  closeBadge: string
  shares: string
  invested: string
  pnl: string
  pnlValue: string
  positive: boolean
  closedAt: string
  icon: string
  logo: string
  tint: string
}

// 交易记录卡片
type ActivityItem = {
  key: string
  title: string
  subtitle: string
  value: string
  detail: string
  shares: string
  positive: boolean
  icon: string
}

const route = useRoute()

const profile = ref({
  name: 'Anon',
  avatarGradient: 'linear-gradient(135deg, #5b6cff 0%, #6c4cf5 35%, #54d38a 100%)',
})

const summary = ref<SummaryState>({
  holdingValue: '$0.00',
  bestProfit: '$0.00',
  predictions: '0',
  pnlLabel: '盈亏',
  pnlValue: '$0.00',
  pnlPeriod: '过去 24 小时',
})

const periodTabs = [
  { label: '1天', value: '1D', pnlInterval: '1d', pnlFidelity: '1h' },
  { label: '1周', value: '1W', pnlInterval: '1w', pnlFidelity: '3h' },
  { label: '1个月', value: '1M', pnlInterval: '1m', pnlFidelity: '18h' },
  { label: '1年', value: '1Y', pnlInterval: 'all', pnlFidelity: '1d' },
  { label: '年初至今', value: 'YTD', pnlInterval: 'all', pnlFidelity: '1d' },
  { label: '全部', value: 'ALL', pnlInterval: 'all', pnlFidelity: 'all' },
]

const activePeriod = ref('1D')
const activeSegment = ref<Segment>('holding')
const activeStatus = ref<PositionStatus>('active')
const searchKeyword = ref('')
const trendPoints = ref<number[]>([])
const activePositions = ref<any[]>([])
const closedPositions = ref<any[]>([])
const activities = ref<any[]>([])
const userAddress = ref('')

const createPagedState = (limit = 30): PagedState => ({
  loading: false,
  finished: false,
  initialized: false,
  offset: 0,
  limit,
})

const activePaging = ref<PagedState>(createPagedState())
const closedPaging = ref<PagedState>(createPagedState())
const activityPaging = ref<PagedState>(createPagedState())

let summaryValueRequestId = 0
let statsRequestId = 0
let pnlRequestId = 0
let activeRequestId = 0
let closedRequestId = 0
let activityRequestId = 0

// 格式化工具
const findPeriodConfig = () => periodTabs.find(item => item.value === activePeriod.value) || periodTabs[0]

const formatCurrency = (value: unknown) => {
  const numeric = Number(value)
  if (Number.isNaN(numeric)) return '$0.00'
  const sign = numeric < 0 ? '-' : ''
  return `${sign}$${Math.abs(numeric).toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`
}

const formatSummaryCurrency = (value: unknown) => {
  const numeric = Number(value)
  if (Number.isNaN(numeric)) return '$0.00'
  const sign = numeric < 0 ? '-' : ''
  const fractionDigits = Math.abs(numeric) > 1000 ? 0 : 2
  return `${sign}$${Math.abs(numeric).toLocaleString('en-US', {
    minimumFractionDigits: fractionDigits,
    maximumFractionDigits: fractionDigits,
  })}`
}

const formatCompactNumber = (value: unknown) => {
  const numeric = Number(value)
  if (Number.isNaN(numeric)) return '0'
  return numeric.toLocaleString('en-US')
}

const formatShare = (value: unknown) => {
  const numeric = Number(value)
  if (Number.isNaN(numeric)) return '0 份额'
  return `${numeric.toLocaleString('zh-CN', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 1,
  })} 份额`
}

const formatBadge = (value: unknown) => {
  const numeric = Number(value)
  if (Number.isNaN(numeric)) return '0¢'
  return `${Math.round(numeric * 100)}¢`
}

const formatDateTime = (value: unknown) => {
  if (!value) return '结束时间未知'
  const numeric = Number(value)
  const date = Number.isNaN(numeric) ? new Date(String(value)) : new Date(numeric * 1000)
  if (Number.isNaN(date.getTime())) return '结束时间未知'
  return date.toLocaleString('zh-CN', {
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  })
}

const shortenAddress = (value: string) => {
  if (!value) return 'Anon'
  if (value.length <= 12) return value
  return `${value.slice(0, 6)}...${value.slice(-4)}`
}

// 数据映射
const toTitle = (item: any) => String(item?.title || item?.slug || item?.market || '未命名市场')
const toSubtitle = (item: any) => String(item?.outcome || item?.side || '仓位')
const formatChineseSide = (value: unknown) => {
  const raw = String(value || '').trim()
  const normalized = raw.toLowerCase()
  const labels: Record<string, string> = {
    buy: '买入',
    sell: '卖出',
    yes: '买涨',
    no: '买跌',
    up: '买涨',
    down: '买跌',
    long: '做多',
    short: '做空',
    trade: '交易',
    split: '拆分',
    merge: '合并',
    redeem: '兑换',
    reward: '奖励',
    conversion: '转换',
    maker_rebate: '做市返佣',
    referral_reward: '邀请奖励',
  }

  return labels[normalized] || raw || '记录'
}

const tintOptions = ['emerald', 'blue', 'orange', 'gold', 'teal', 'rose'] as const
const iconOptions = ['⚽', '🏀', '🎾', '⚾', '🏒', '📈']
const pickTint = (index: number) => tintOptions[index % tintOptions.length]
const pickLogo = (index: number) => iconOptions[index % iconOptions.length]

const mapPositionToCard = (item: any, index: number): PositionItem => {
  const pnlRaw = Number(item?.cashPnl ?? item?.pnl ?? 0)
  const percentRaw = Number(item?.percentPnl ?? item?.roi ?? 0)
  return {
    key: String(item?.asset || item?.conditionId || item?.slug || index),
    tokenId: String(item?.asset || item?.token_id || item?.tokenId || ''),
    marketId: String(item?.conditionId || item?.market_id || item?.marketId || ''),
    title: toTitle(item),
    subtitle: toSubtitle(item),
    badge: formatBadge(item?.curPrice ?? item?.avgPrice ?? item?.price ?? 0),
    shares: formatShare(item?.size ?? item?.totalBought ?? 0),
    value: formatCurrency(item?.currentValue ?? item?.cashValue ?? item?.value ?? 0),
    pnl: `${formatCurrency(pnlRaw)} (${percentRaw.toFixed(2)}%)`,
    positive: pnlRaw >= 0,
    icon: String(item?.icon || ''),
    logo: pickLogo(index),
    tint: pickTint(index),
  }
}

const mapClosedPositionToCard = (item: any, index: number): ClosedPositionItem => {
  const totalBought = Number(item?.totalBought ?? item?.size ?? 0)
  const investedRaw = Number(item?.initialValue ?? item?.totalBoughtValue ?? 0)
  const avgPrice = Number(item?.avgPrice ?? item?.price ?? 0)
  const closePrice = Number(item?.curPrice ?? item?.closePrice ?? 0)
  const pnlValueRaw = totalBought * closePrice
  const percentRaw = avgPrice > 0 ? ((closePrice - avgPrice) / avgPrice) * 100 : 0
  const realizedPnl = Number(item?.realizedPnl ?? 0).toFixed(2)

  return {
    key: String(item?.asset || item?.conditionId || item?.slug || item?.timestamp || index),
    title: toTitle(item),
    subtitle: toSubtitle(item),
    openBadge: `买入 ${formatBadge(avgPrice)}`,
    closeBadge: `结束 ${formatBadge(closePrice)}`,
    shares: formatShare(totalBought),
    invested: formatCurrency(investedRaw || avgPrice * totalBought),
    pnl: realizedPnl + `(` + `${Number.isNaN(percentRaw) ? '0.00' : percentRaw.toFixed(2)}%` + `)`,
    pnlValue: formatCurrency(pnlValueRaw),
    positive: percentRaw >= 0,
    closedAt: formatDateTime(item?.timestamp ?? item?.endDate),
    icon: String(item?.icon || ''),
    logo: pickLogo(index),
    tint: pickTint(index),
  }
}

const mapActivityToCard = (item: any, index: number): ActivityItem => {
  const title = String(item?.title || item?.slug || item?.marketTitle || '交易记录')
  const side = String(item?.side || item?.type || item?.outcome || '记录')
  const size = Number(item?.size ?? item?.shares ?? item?.amount ?? 0)
  const usdcSize = Number(item?.usdcSize ?? item?.size_usdc ?? item?.value ?? 0)
  const price = Number(item?.price ?? item?.curPrice ?? 0)
  const positive = side.toUpperCase().includes('BUY') || side === 'Yes'
  return {
    key: String(item?.id || item?.transactionHash || `${title}-${index}`),
    title,
    subtitle: formatChineseSide(side),
    value: formatCurrency(usdcSize || (size && price ? size * price : 0)),
    detail: item?.timestamp ? new Date(Number(item.timestamp) * 1000).toLocaleString('zh-CN') : '最近成交',
    shares: formatShare(size),
    positive,
    icon: String(item?.icon || ''),
  }
}

const activePositionCards = computed(() => {
  const keyword = searchKeyword.value.trim().toLowerCase()
  return activePositions.value
    .filter(item => {
      if (!keyword) return true
      return `${toTitle(item)} ${toSubtitle(item)}`.toLowerCase().includes(keyword)
    })
    .map((item, index) => mapPositionToCard(item, index))
})

const closedPositionCards = computed(() => {
  const keyword = searchKeyword.value.trim().toLowerCase()
  return closedPositions.value
    .filter(item => {
      if (!keyword) return true
      return `${toTitle(item)} ${toSubtitle(item)}`.toLowerCase().includes(keyword)
    })
    .map((item, index) => mapClosedPositionToCard(item, index))
})

const activityCards = computed(() => activities.value.map((item, index) => mapActivityToCard(item, index)))

const chartPath = computed(() => {
  const points = trendPoints.value.length > 1 ? trendPoints.value : [0, 0, 0, 0, 0, 0]
  const width = 500
  const height = 120
  const min = Math.min(...points)
  const max = Math.max(...points)
  const range = max - min || 1
  const coordinates = points.map((point, index) => ({
    x: (index / Math.max(points.length - 1, 1)) * (width - 8) + 4,
    y: height - ((point - min) / range) * (height - 24) - 12,
  }))

  if (coordinates.length < 3) {
    return coordinates
      .map((point, index) => `${index === 0 ? 'M' : 'L'} ${point.x.toFixed(2)} ${point.y.toFixed(2)}`)
      .join(' ')
  }

  const lastIndex = coordinates.length - 1
  let path = `M ${coordinates[0].x.toFixed(2)} ${coordinates[0].y.toFixed(2)}`

  for (let index = 1; index < lastIndex; index++) {
    const current = coordinates[index]
    const next = coordinates[index + 1]
    const midX = (current.x + next.x) / 2
    const midY = (current.y + next.y) / 2
    path += ` Q ${current.x.toFixed(2)} ${current.y.toFixed(2)} ${midX.toFixed(2)} ${midY.toFixed(2)}`
  }

  const lastControl = coordinates[lastIndex - 1]
  const lastPoint = coordinates[lastIndex]
  path += ` Q ${lastControl.x.toFixed(2)} ${lastControl.y.toFixed(2)} ${lastPoint.x.toFixed(2)} ${lastPoint.y.toFixed(2)}`

  return path
})

const extractBestProfit = (stats: any) => {
  const numeric = Number(stats?.largestWin)
  return Number.isNaN(numeric) ? 0 : numeric
}

const extractPredictions = (stats: any) => {
  const candidates = [
    stats?.predictions,
    stats?.predictionCount,
    stats?.prediction_count,
    stats?.trades,
    stats?.tradeCount,
    stats?.trade_count,
    stats?.markets,
  ]

  for (const candidate of candidates) {
    const numeric = Number(candidate)
    if (!Number.isNaN(numeric)) {
      return numeric
    }
  }

  return 0
}

const normalizePnlSeries = (payload: any) => {
  const source = Array.isArray(payload)
    ? payload
    : Array.isArray(payload?.history)
      ? payload.history
      : Array.isArray(payload?.pnl)
        ? payload.pnl
        : []

  return source
    .map((item: any) => Number(item?.p ?? item?.pnl ?? item?.value ?? item?.amount ?? item?.y ?? 0))
    .filter((value: number) => !Number.isNaN(value))
}

const resolveUserAddress = async () => {
  return String(route.params.proxyWallet || '').toLowerCase()
}

const resetPagedState = (state: PagedState) => {
  state.loading = false
  state.finished = false
  state.initialized = false
  state.offset = 0
}

const mergeUniqueItems = (current: any[], incoming: any[], resolver: (item: any, index: number) => string) => {
  const map = new Map<string, any>()
  current.forEach((item, index) => {
    map.set(resolver(item, index), item)
  })
  incoming.forEach((item, index) => {
    map.set(resolver(item, current.length + index), item)
  })
  return Array.from(map.values())
}

const activeKeyResolver = (item: any, index: number) => String(item?.asset || item?.conditionId || item?.slug || `active-${index}`)
const closedKeyResolver = (item: any, index: number) => String(item?.asset || item?.conditionId || item?.timestamp || item?.slug || `closed-${index}`)
const activityKeyResolver = (item: any, index: number) => String(item?.id || item?.transactionHash || `${item?.title || item?.slug || 'activity'}-${index}`)

const loadSummaryValue = async (address: string) => {
  const requestId = ++summaryValueRequestId
  const { data } = await http.get('/markets/value', { params: { user: address } })
  if (requestId !== summaryValueRequestId) return

  const valuePayload = data?.data?.value ?? {}
  const valueSummary = Array.isArray(valuePayload) ? (valuePayload[0] ?? {}) : valuePayload
  summary.value = {
    ...summary.value,
    holdingValue: formatSummaryCurrency(valueSummary?.value ?? 0),
  }
}

const loadUserStats = async (address: string) => {
  const requestId = ++statsRequestId
  const { data } = await http.get('/markets/user-stats', { params: { address } })
  if (requestId !== statsRequestId) return

  const statsPayload = data?.data?.stats ?? {}
  summary.value = {
    ...summary.value,
    bestProfit: formatSummaryCurrency(extractBestProfit(statsPayload)),
    predictions: formatCompactNumber(extractPredictions(statsPayload)),
  }
}

const loadPnlData = async (address: string) => {
  const requestId = ++pnlRequestId
  if (!address) {
    trendPoints.value = []
    summary.value = {
      ...summary.value,
      pnlValue: '$0.00',
      pnlPeriod: activePeriod.value === '1D' ? '过去 24 小时' : '区间盈亏走势',
    }
    return
  }

  const periodConfig = findPeriodConfig()
  const { data } = await http.get('/markets/user-pnl', {
    params: {
      user_address: address,
      interval: periodConfig.pnlInterval,
      fidelity: periodConfig.pnlFidelity,
    },
  })

  if (requestId !== pnlRequestId) return

  const pnlPayload = data?.data?.pnl ?? []
  trendPoints.value = normalizePnlSeries(pnlPayload)
  const pnlSeries = trendPoints.value
  const latestPnl = pnlSeries.length > 1 ? pnlSeries[pnlSeries.length - 1] - pnlSeries[0] : 0

  summary.value = {
    ...summary.value,
    pnlValue: formatCurrency(latestPnl),
    pnlPeriod: activePeriod.value === '1D' ? '过去 24 小时' : '区间盈亏走势',
  }
}

const loadActivePositions = async (reset = false) => {
  if (!userAddress.value) return
  const state = activePaging.value
  if (state.loading || (!reset && state.finished)) return

  const requestId = ++activeRequestId
  if (reset) {
    activePositions.value = []
    resetPagedState(state)
  }

  state.loading = true
  try {
    const { data } = await http.get('/markets/positions', {
      params: { user: userAddress.value, limit: state.limit, offset: reset ? 0 : state.offset },
    })
    if (requestId !== activeRequestId) return

    const incoming = Array.isArray(data?.data?.list) ? data.data.list : []
    const previousLength = activePositions.value.length
    activePositions.value = reset
      ? incoming
      : mergeUniqueItems(activePositions.value, incoming, activeKeyResolver)
    state.initialized = true
    state.offset = activePositions.value.length
    state.finished = incoming.length < state.limit || (!reset && incoming.length > 0 && activePositions.value.length === previousLength)
  } finally {
    state.loading = false
  }
}

const loadClosedPositions = async (reset = false) => {
  if (!userAddress.value) return
  const state = closedPaging.value
  if (state.loading || (!reset && state.finished)) return

  const requestId = ++closedRequestId
  if (reset) {
    closedPositions.value = []
    resetPagedState(state)
  }

  state.loading = true
  try {
    const { data } = await http.get('/markets/closed-positions', {
      params: { user: userAddress.value, limit: state.limit, offset: reset ? 0 : state.offset },
    })
    if (requestId !== closedRequestId) return

    const incoming = Array.isArray(data?.data?.list) ? data.data.list : []
    const previousLength = closedPositions.value.length
    closedPositions.value = reset
      ? incoming
      : mergeUniqueItems(closedPositions.value, incoming, closedKeyResolver)
    state.initialized = true
    state.offset = closedPositions.value.length
    state.finished = incoming.length < state.limit || (!reset && incoming.length > 0 && closedPositions.value.length === previousLength)
  } finally {
    state.loading = false
  }
}

const loadActivities = async (reset = false) => {
  if (!userAddress.value) return
  const state = activityPaging.value
  if (state.loading || (!reset && state.finished)) return

  const requestId = ++activityRequestId
  if (reset) {
    activities.value = []
    resetPagedState(state)
  }

  state.loading = true
  try {
    const { data } = await http.get('/markets/activity', {
      params: { user: userAddress.value, limit: state.limit, offset: reset ? 0 : state.offset },
    })
    if (requestId !== activityRequestId) return

    const incoming = Array.isArray(data?.data?.list) ? data.data.list : []
    const previousLength = activities.value.length
    activities.value = reset
      ? incoming
      : mergeUniqueItems(activities.value, incoming, activityKeyResolver)
    state.initialized = true
    state.offset = activities.value.length
    state.finished = incoming.length < state.limit || (!reset && incoming.length > 0 && activities.value.length === previousLength)
  } finally {
    state.loading = false
  }
}

const loadHomeData = async () => {
  const address = await resolveUserAddress()
  userAddress.value = address

  if (!address) {
    return
  }

  profile.value = {
    ...profile.value,
    name: shortenAddress(address),
  }

  summary.value = {
    ...summary.value,
    holdingValue: '$0.00',
    bestProfit: '$0.00',
    predictions: '0',
  }

  loadSummaryValue(address).catch(() => null)
  loadUserStats(address).catch(() => null)
  loadPnlData(address).catch(() => null)
  await loadActivePositions(true)
}

const setPeriod = async (value: string) => {
  activePeriod.value = value
  await loadPnlData(userAddress.value)
}

const switchToActiveTab = async () => {
  activeSegment.value = 'holding'
  activeStatus.value = 'active'
  if (!activePaging.value.initialized) {
    await loadActivePositions(true)
  }
}

const switchToClosedTab = async () => {
  activeSegment.value = 'holding'
  activeStatus.value = 'closed'
  if (!closedPaging.value.initialized) {
    await loadClosedPositions(true)
  }
}

const switchToRecordsTab = async () => {
  activeSegment.value = 'records'
  if (!activityPaging.value.initialized) {
    await loadActivities(true)
  }
}

const copyAddress = async () => {
  if (!userAddress.value) {
    showToast('暂无地址可复制')
    return
  }

  try {
    await navigator.clipboard.writeText(userAddress.value)
    showToast('地址已复制')
  } catch {
    showToast('复制失败')
  }
}

const openPolymarketProfile = async () => {
  if (!userAddress.value) {
    showToast('暂无地址可跳转')
    return
  }

  await showConfirmDialog({
    title: '提示',
    message: '将跳转到polymarket',
    confirmButtonText: '确认',
    cancelButtonText: '取消',
  })

  window.location.href = `https://polymarket.com/zh/profile/${userAddress.value}`
}

onMounted(async () => {
  await loadHomeData()
})
</script>

<template>
  <div class="portfolio-page">
    <header class="portfolio-hero">
      <div class="portfolio-profile">
        <div class="portfolio-avatar" :style="{ background: profile.avatarGradient }"></div>
        <div>
          <div class="portfolio-name">{{ profile.name }}</div>
        </div>
      </div>
      <div class="portfolio-actions">
        <button class="ghost-icon" type="button" aria-label="copy address" @click="copyAddress">
          <span>👤</span>
        </button>
        <button class="ghost-icon" type="button" aria-label="share" @click="openPolymarketProfile">
          <span>↗</span>
        </button>
      </div>
    </header>

    <section class="summary-strip surface-card">
      <div class="summary-item">
        <div class="summary-value">{{ summary.holdingValue }}</div>
        <div class="summary-label">持仓价值</div>
      </div>
      <div class="summary-item">
        <div class="summary-value">{{ summary.bestProfit }}</div>
        <div class="summary-label">最高盈利</div>
      </div>
      <div class="summary-item">
        <div class="summary-value">{{ summary.predictions }}</div>
        <div class="summary-label">预测</div>
      </div>
    </section>

    <section class="pnl-card glass-card">
      <div class="pnl-header">
        <div class="pnl-meta">
          <span class="pnl-dot"></span>
          <span>{{ summary.pnlLabel }}</span>
        </div>
        <div class="pnl-tabs">
          <button
            v-for="tab in periodTabs"
            :key="tab.value"
            type="button"
            class="period-tab"
            :class="{ 'period-tab--active': activePeriod === tab.value }"
            @click="setPeriod(tab.value)"
          >
            {{ tab.label }}
          </button>
        </div>
      </div>

      <div class="pnl-number">{{ summary.pnlValue }}</div>
      <div class="pnl-period">{{ summary.pnlPeriod }}</div>

      <div class="chart-shell">
        <svg viewBox="0 0 500 120" class="trend-chart" preserveAspectRatio="none">
          <defs>
            <linearGradient id="trendLine" x1="0%" y1="0%" x2="100%" y2="0%">
              <stop offset="0%" stop-color="#5b4eff" />
              <stop offset="100%" stop-color="#6f63ff" />
            </linearGradient>
          </defs>
          <path :d="chartPath" fill="none" stroke="url(#trendLine)" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
      </div>
    </section>

    <section class="content-block">
      <div class="toolbar-row">
        <button
          type="button"
          class="toolbar-pill"
          :class="{ 'toolbar-pill--active': activeSegment === 'holding' && activeStatus === 'active' }"
          @click="switchToActiveTab"
        >
          生效中
        </button>
        <button
          type="button"
          class="toolbar-pill"
          :class="{ 'toolbar-pill--active': activeSegment === 'holding' && activeStatus === 'closed' }"
          @click="switchToClosedTab"
        >
          已结束
        </button>
        <button
          type="button"
          class="toolbar-pill"
          :class="{ 'toolbar-pill--active': activeSegment === 'records' }"
          @click="switchToRecordsTab"
        >
          交易记录
        </button>
      </div>
    </section>

    <section v-if="activeSegment === 'holding' && activeStatus === 'active'" class="position-list">
      <van-list :loading="activePaging.loading" :finished="activePaging.finished" finished-text="没有更多生效中持仓了" @load="loadActivePositions(false)">
        <article v-for="item in activePositionCards" :key="item.key" class="position-card surface-card">
          <div class="position-card__left">
            <div class="position-logo" :class="`position-logo--${item.tint}`">
              <img v-if="item.icon" :src="item.icon" :alt="item.title" class="position-logo__image" />
              <span v-else>{{ item.logo }}</span>
            </div>
            <div class="position-copy">
              <h3 class="position-title">{{ item.title }}</h3>
              <div class="position-meta">
                <span class="position-badge" :class="{ 'position-badge--negative': !item.positive }">{{ item.subtitle }} {{ item.badge }}</span>
                <span class="position-shares">{{ item.shares }}</span>
              </div>
            </div>
          </div>
          <div class="position-card__right">
            <div class="position-value">{{ item.value }}</div>
            <div class="position-pnl" :class="{ 'position-pnl--negative': !item.positive }">{{ item.pnl }}</div>
          </div>
        </article>
      </van-list>

      <div v-if="activePaging.initialized && !activePositionCards.length && !activePaging.loading" class="empty-records surface-card">
        <div class="empty-records__title">暂无生效中持仓</div>
        <p class="empty-records__text">当前没有可展示的生效中持仓数据。</p>
      </div>
    </section>

    <section v-else-if="activeSegment === 'holding' && activeStatus === 'closed'" class="position-list">
      <van-list :loading="closedPaging.loading" :finished="closedPaging.finished" finished-text="没有更多已结束持仓了" @load="loadClosedPositions(false)">
        <article v-for="item in closedPositionCards" :key="item.key" class="position-card position-card--closed surface-card">
          <div class="position-card__left">
            <div class="position-logo" :class="`position-logo--${item.tint}`">
              <img v-if="item.icon" :src="item.icon" :alt="item.title" class="position-logo__image" />
              <span v-else>{{ item.logo }}</span>
            </div>
            <div class="position-copy">
              <h3 class="position-title">{{ item.title }}</h3>
              <div class="position-meta">
                <span class="position-badge" :class="{ 'position-badge--negative': !item.positive }">{{ item.subtitle }}</span>
                <span class="position-shares">{{ item.closedAt }}</span>
              </div>
              <div class="closed-position-prices">
                <span>{{ item.openBadge }}</span>
                <span>{{ item.closeBadge }}</span>
              </div>
            </div>
          </div>
          <div class="position-card__right">
            <div class="position-value">{{ item.pnlValue }}</div>
            <div class="position-pnl" :class="{ 'position-pnl--negative': !item.positive }">{{ item.pnl }}</div>
            <div class="position-shares">投入 {{ item.invested }}</div>
            <span>{{ item.shares }}</span>
          </div>
        </article>
      </van-list>

      <div v-if="closedPaging.initialized && !closedPositionCards.length && !closedPaging.loading" class="empty-records surface-card">
        <div class="empty-records__title">暂无已结束持仓</div>
        <p class="empty-records__text">当前没有可展示的已结束持仓数据。</p>
      </div>
    </section>

    <section v-else class="position-list">
      <van-list :loading="activityPaging.loading" :finished="activityPaging.finished" finished-text="没有更多交易记录了" @load="loadActivities(false)">
        <article v-for="item in activityCards" :key="item.key" class="position-card surface-card">
          <div class="position-card__left">
            <div class="position-logo" :class="`position-logo--${item.positive ? 'emerald' : 'rose'}`">
              <img v-if="item.icon" :src="item.icon" :alt="item.title" class="position-logo__image" />
              <span v-else>{{ item.positive ? '↗' : '↘' }}</span>
            </div>
            <div class="position-copy">
              <h3 class="position-title">{{ item.title }}</h3>
              <div class="position-meta">
                <span class="position-badge" :class="{ 'position-badge--negative': !item.positive }">{{ item.subtitle }}</span>
                <span class="position-shares">{{ item.detail }}</span>
                <span class="position-shares">{{ item.shares }}</span>
              </div>
            </div>
          </div>
          <div class="position-card__right">
            <div class="position-value">{{ item.value }}</div>
          </div>
        </article>
      </van-list>

      <div v-if="activityPaging.initialized && !activityCards.length && !activityPaging.loading" class="empty-records surface-card">
        <div class="empty-records__title">暂无交易记录</div>
        <p class="empty-records__text">当前没有可展示的活动数据。</p>
      </div>
    </section>
  </div>
</template>

<style scoped>
.portfolio-page {
  min-height: 100vh;
  max-width: 520px;
  margin: 0 auto;
  padding: var(--page-y) var(--page-x) calc(var(--safe-bottom) + 24px);
  box-sizing: border-box;
  overflow-x: clip;
  background:
    radial-gradient(circle at top left, rgba(87, 103, 255, 0.16), transparent 26%),
    linear-gradient(180deg, #fbfbff 0%, #f2f4fb 36%, #eef1f7 100%);
}

.portfolio-hero {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 18px;
}

.portfolio-profile {
  display: flex;
  align-items: center;
  gap: 12px;
}

.portfolio-avatar {
  width: 54px;
  height: 54px;
  border-radius: 50%;
  box-shadow: 0 12px 28px rgba(79, 91, 255, 0.22);
}

.portfolio-name {
  font-size: 20px;
  font-weight: 800;
  letter-spacing: -0.03em;
  color: #111827;
}

.portfolio-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

.ghost-icon {
  width: 36px;
  height: 36px;
  border: 0;
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.72);
  box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
  color: #1f2937;
  font-size: 18px;
}

.summary-strip {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0;
  margin-bottom: 18px;
  padding: 18px 12px;
}

.summary-item {
  position: relative;
  text-align: center;
}

.summary-item:not(:last-child)::after {
  content: '';
  position: absolute;
  top: 4px;
  right: 0;
  width: 1px;
  height: calc(100% - 8px);
  background: rgba(148, 163, 184, 0.26);
}

.summary-value {
  font-size: 18px;
  font-weight: 800;
  color: #111827;
  letter-spacing: -0.04em;
}

.summary-label {
  margin-top: 4px;
  font-size: 12px;
  color: #6b7280;
}

.pnl-card {
  padding: 18px 16px 16px;
  border-radius: 26px;
  margin-bottom: 20px;
}

.pnl-header {
  display: grid;
  gap: 14px;
}

.pnl-meta {
  display: flex;
  align-items: center;
  gap: 8px;
  color: #ef4444;
  font-size: 13px;
  font-weight: 700;
}

.pnl-dot {
  width: 0;
  height: 0;
  border-left: 5px solid transparent;
  border-right: 5px solid transparent;
  border-top: 8px solid #ef4444;
}

.pnl-tabs {
  display: flex;
  gap: 6px;
  overflow-x: auto;
  padding-bottom: 4px;
}

.period-tab {
  flex: 0 0 auto;
  border: 0;
  border-radius: 999px;
  padding: 6px 10px;
  background: transparent;
  color: #6b7280;
  font-size: 12px;
  font-weight: 700;
}

.period-tab--active {
  background: #edf2ff;
  color: #4f46e5;
}

.pnl-number {
  margin-top: 14px;
  font-size: 44px;
  line-height: 1;
  font-weight: 800;
  letter-spacing: -0.08em;
  color: #111827;
}

.pnl-period {
  margin-top: 6px;
  color: #9ca3af;
  font-size: 13px;
}

.chart-shell {
  margin-top: 12px;
  border-radius: 18px;
  background: linear-gradient(180deg, rgba(255,255,255,0.72), rgba(255,255,255,0.35));
  overflow: hidden;
}

.trend-chart {
  width: 100%;
  height: 140px;
  display: block;
}

.content-block {
  margin-bottom: 14px;
}

.toolbar-row {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
  margin-bottom: 12px;
}

.toolbar-pill {
  border: 0;
  border-radius: 14px;
  padding: 11px 10px;
  background: rgba(255, 255, 255, 0.86);
  color: #4b5563;
  font-size: 14px;
  font-weight: 700;
  box-shadow: 0 10px 22px rgba(15, 23, 42, 0.06);
}

.toolbar-pill--active {
  background: #f3f4f6;
  color: #111827;
}

.position-list {
  display: grid;
  gap: 12px;
}

.position-card {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  padding: 14px;
}

.position-card--closed {
  background: linear-gradient(135deg, rgba(255,255,255,0.96), rgba(248,250,252,0.88));
}

.position-card__left {
  display: flex;
  gap: 12px;
  min-width: 0;
}

.position-logo {
  flex: 0 0 auto;
  width: 52px;
  height: 52px;
  border-radius: 16px;
  display: grid;
  place-items: center;
  overflow: hidden;
  font-size: 25px;
  box-shadow: inset 0 0 0 1px rgba(255,255,255,0.6);
}

.position-logo__image {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.position-logo--emerald { background: linear-gradient(135deg, #d1fae5, #86efac); }
.position-logo--blue { background: linear-gradient(135deg, #dbeafe, #bfdbfe); }
.position-logo--orange { background: linear-gradient(135deg, #fff7ed, #fed7aa); }
.position-logo--gold { background: linear-gradient(135deg, #fef3c7, #fde68a); }
.position-logo--teal { background: linear-gradient(135deg, #ccfbf1, #99f6e4); }
.position-logo--rose { background: linear-gradient(135deg, #ffe4e6, #fecdd3); }

.position-copy {
  min-width: 0;
}

.position-title {
  margin: 0;
  color: #111827;
  font-size: 15px;
  line-height: 1.35;
  font-weight: 700;
}

.position-meta {
  margin-top: 8px;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}

.position-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  min-height: 28px;
  padding: 0 10px;
  border-radius: 999px;
  background: #dcfce7;
  color: #16a34a;
  font-size: 13px;
  font-weight: 700;
}

.position-badge--negative {
  background: #fee2e2;
  color: #ef4444;
}

.position-shares {
  color: #9ca3af;
  font-size: 13px;
}

.closed-position-prices {
  margin-top: 8px;
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  color: #64748b;
  font-size: 12px;
  font-weight: 700;
}

.closed-position-prices span {
  padding: 5px 8px;
  border-radius: 999px;
  background: rgba(241, 245, 249, 0.9);
}

.position-card__right {
  text-align: right;
  flex: 0 0 auto;
}

.position-value {
  color: #111827;
  font-size: 15px;
  font-weight: 800;
}

.position-pnl {
  margin-top: 8px;
  color: #16a34a;
  font-size: 13px;
  font-weight: 700;
}

.position-pnl--negative {
  color: #ef4444;
}

.empty-records {
  padding: 18px;
}

.empty-records__title {
  color: #111827;
  font-size: 16px;
  font-weight: 800;
}

.empty-records__text {
  margin: 8px 0 0;
  color: #6b7280;
  font-size: 14px;
  line-height: 1.6;
}
</style>
