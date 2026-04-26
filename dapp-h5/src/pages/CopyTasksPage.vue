<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { showConfirmDialog, showFailToast, showSuccessToast } from 'vant'
import { useAppStore } from '../stores/app'
import http from '../api/http'

const store = useAppStore()
const savingTask = ref(false)
const resolvingLeader = ref(false)
const resolvingMarket = ref(false)
const deletingTaskId = ref<number | null>(null)
const togglingTaskId = ref<number | null>(null)
const editingTask = ref<any>(null)
const showEditDialog = ref(false)
const saveEditLoading = ref(false)
const leaders = ref<any[]>([])
const form = reactive({
  mode: 'leader_copy',
  address: '',
  leader_id: 0,
  market_input: '',
  market_slug: '',
  market_id: '',
  market_question: '',
  market_symbol: 'btc/usd',
  resolution_source: '',
  price_to_beat: '',
  market_end_at: '',
  token_yes_id: '',
  token_no_id: '',
  ratio_bps: 10000,
  min_usdc: 0,
  max_usdc: 0,
  maker_max_quantity_per_token: '',
  tail_order_usdc: 0,
  tail_trigger_amount: '200',
  tail_time_limit_seconds: 30,
  tail_loss_stop_count: 0,
  tail_price_time_config: [] as any[],
})

const editForm = reactive({
  ratio_bps: 10000,
  min_usdc: 0,
  max_usdc: 0,
  maker_max_quantity_per_token: '',
  tail_order_usdc: 0,
  tail_trigger_amount: '200',
  tail_time_limit_seconds: 30,
  tail_loss_stop_count: 0,
  tail_price_time_config: [] as any[],
})

const isLeaderMode = computed(() => form.mode === 'leader_copy')
const marketResolved = computed(() => !!form.market_slug && !!form.market_id && !!form.token_yes_id && !!form.token_no_id && !!form.price_to_beat)
const taskActionLoading = computed(() => savingTask.value || resolvingLeader.value || resolvingMarket.value)

const modeOptions = [
  { name: 'Leader跟单', value: 'leader_copy' },
  { name: '扫尾盘(单单)', value: 'tail_sweep' },
  { name: '扫尾盘(多单)', value: 'tail_sweep_many' }
]

const showModeSelect = ref(false)

const getModeLabel = (mode: string) => {
  return modeOptions.find(opt => opt.value === mode)?.name || '选择模式'
}

watch(() => form.address, () => {
  form.leader_id = 0
})

watch(() => form.market_input, () => {
  form.market_slug = ''
  form.market_id = ''
  form.market_question = ''
  form.market_symbol = 'btc/usd'
  form.resolution_source = ''
  form.price_to_beat = ''
  form.market_end_at = ''
  form.token_yes_id = ''
  form.token_no_id = ''
})

watch(() => form.mode, (mode) => {
  if (mode === 'leader_copy') {
    form.market_input = ''
  } else if (mode === 'tail_sweep' || mode === 'tail_sweep_many') {
    form.address = ''
    form.leader_id = 0
  }
})

const resolveLeader = async () => {
  if (!form.address) {
    showFailToast('请输入 leader 地址')
    return
  }
  resolvingLeader.value = true
  try {
    const { data } = await http.post('/leaders/resolve', { address: form.address })
    const leader = data.data.leader
    form.leader_id = leader.id
    if (!leaders.value.some(item => item.id === leader.id)) {
      leaders.value = [leader, ...leaders.value]
    }
    showSuccessToast('leader 已解析')
  } catch (error: any) {
    showFailToast(error.message || '解析失败')
  } finally {
    resolvingLeader.value = false
  }
}

const resolveMarket = async () => {
  if (!form.market_input) {
    showFailToast('请输入市场链接或 slug')
    return
  }
  resolvingMarket.value = true
  try {
    const { data } = await http.post('/markets/resolve', { input: form.market_input })
    const market = data.data.market
    form.market_slug = market.slug || ''
    form.market_id = market.market_id || ''
    form.market_question = market.question || ''
    form.market_symbol = market.symbol || 'btc/usd'
    form.resolution_source = market.resolution_source || ''
    form.price_to_beat = market.price_to_beat || ''
    form.market_end_at = market.end_at || ''
    form.token_yes_id = market.token_yes_id || ''
    form.token_no_id = market.token_no_id || ''
    showSuccessToast('市场解析成功，可以保存任务了')
  } catch (error: any) {
    showFailToast(error.message || '解析失败')
  } finally {
    resolvingMarket.value = false
  }
}

const saveTask = async () => {
  if (savingTask.value) return

  savingTask.value = true
  try {
    if (isLeaderMode.value) {
      if (!form.leader_id) {
        showFailToast('请先解析 leader')
        savingTask.value = false
        return
      }
      await http.post('/copy-tasks', {
        mode: 'leader_copy',
        leader_id: form.leader_id,
        ratio_bps: form.ratio_bps,
        min_usdc: form.min_usdc,
        max_usdc: form.max_usdc,
        maker_max_quantity_per_token: form.maker_max_quantity_per_token,
      })
    } else {
      if (!marketResolved.value) {
        showFailToast('请先解析市场')
        savingTask.value = false
        return
      }
      // 转换价格-时间配置为后端需要的格式
      let priceTimeConfig = null
      if (form.tail_price_time_config.length > 0) {
        const config: any = {}
        config[form.market_symbol] = {}
        form.tail_price_time_config.forEach((rule: any) => {
          config[form.market_symbol][rule.price] = rule.time
        })
        priceTimeConfig = config
      }

      await http.post('/copy-tasks', {
        mode: form.mode,
        market_slug: form.market_slug,
        market_id: form.market_id,
        market_question: form.market_question,
        market_symbol: form.market_symbol,
        resolution_source: form.resolution_source,
        price_to_beat: form.price_to_beat,
        market_end_at: form.market_end_at,
        token_yes_id: form.token_yes_id,
        token_no_id: form.token_no_id,
        tail_order_usdc: form.tail_order_usdc,
        tail_trigger_amount: form.tail_trigger_amount,
        tail_time_limit_seconds: form.tail_time_limit_seconds,
        tail_loss_stop_count: form.tail_loss_stop_count,
        tail_price_time_config: priceTimeConfig,
      })
    }
    await store.fetchCopyTasks()
    showSuccessToast('任务已保存')
  } catch (error: any) {
    showFailToast(error.message || '保存失败')
  } finally {
    savingTask.value = false
  }
}

const toggleTask = async (task: any) => {
  if (togglingTaskId.value !== null || deletingTaskId.value !== null) {
    return
  }

  togglingTaskId.value = task.id
  const nextStatus = task.status === 1 ? 0 : 1
  const path = task.status === 1 ? `/copy-tasks/${task.id}/pause` : `/copy-tasks/${task.id}/resume`

  try {
    await http.post(path)
    const target = store.copyTasks.find(item => item.id === task.id)
    if (target) {
      target.status = nextStatus
    }
  } catch (error: any) {
    showFailToast(error.message || '操作失败')
  } finally {
    togglingTaskId.value = null
  }
}

const removeTask = async (task: any) => {
  try {
    await showConfirmDialog({
      title: '确认删除任务？',
      message: '删除后该任务会从列表隐藏，后续重新添加时会恢复并按最新配置保存。',
      confirmButtonText: '确认删除',
      cancelButtonText: '取消',
    })
  } catch {
    return
  }

  deletingTaskId.value = task.id
  try {
    await http.delete(`/copy-tasks/${task.id}`)
    await store.fetchCopyTasks()
    showSuccessToast('任务已删除')
  } catch (error: any) {
    showFailToast(error.message || '删除失败')
  } finally {
    deletingTaskId.value = null
  }
}

const openEditDialog = (task: any) => {
  console.log('打开编辑对话框，任务数据:', task)
  editingTask.value = task
  if (task.mode === 'tail_sweep' || task.mode === 'tail_sweep_many') {
    editForm.tail_order_usdc = parseInt(task.tail_order_usdc) || 0
    editForm.tail_trigger_amount = task.tail_trigger_amount || '200'
    editForm.tail_time_limit_seconds = task.tail_time_limit_seconds || 30
    editForm.tail_loss_stop_count = task.tail_loss_stop_count || 0

    // 转换后端对象格式为前端数组格式
    const config = task.tail_price_time_config
    console.log('原始配置:', config)
    if (config && typeof config === 'object') {
      const symbol = task.market?.symbol || 'btc/usd'
      console.log('标的:', symbol)
      const symbolConfig = config[symbol]
      console.log('标的配置:', symbolConfig)
      if (symbolConfig && typeof symbolConfig === 'object') {
        editForm.tail_price_time_config = Object.entries(symbolConfig).map(([price, time]) => ({
          price: parseInt(price),
          time: time as number
        }))
        console.log('转换后的配置数组:', editForm.tail_price_time_config)
      } else {
        editForm.tail_price_time_config = []
      }
    } else {
      editForm.tail_price_time_config = []
    }
  } else {
    editForm.ratio_bps = task.ratio_bps || 10000
    editForm.min_usdc = parseInt(task.min_usdc) || 0
    editForm.max_usdc = parseInt(task.max_usdc) || 0
    editForm.maker_max_quantity_per_token = task.maker_max_quantity_per_token || ''
  }
  showEditDialog.value = true
}

const addPriceTimeRule = () => {
  editForm.tail_price_time_config.push({ price: 100, time: 60 })
}

const removePriceTimeRule = (index: number) => {
  editForm.tail_price_time_config.splice(index, 1)
}

const saveEdit = async () => {
  if (!editingTask.value || saveEditLoading.value) return

  saveEditLoading.value = true
  try {
    const payload: any = {}
    if (editingTask.value.mode === 'tail_sweep' || editingTask.value.mode === 'tail_sweep_many') {
      payload.tail_order_usdc = editForm.tail_order_usdc
      payload.tail_trigger_amount = editForm.tail_trigger_amount
      payload.tail_time_limit_seconds = editForm.tail_time_limit_seconds
      payload.tail_loss_stop_count = editForm.tail_loss_stop_count

      // 转换价格-时间配置为后端需要的格式
      const symbol = editingTask.value.market?.symbol || 'btc/usd'
      if (editForm.tail_price_time_config.length > 0) {
        const config: any = {}
        config[symbol] = {}
        editForm.tail_price_time_config.forEach((rule: any) => {
          const priceKey = String(rule.price)
          config[symbol][priceKey] = Number(rule.time)
        })
        payload.tail_price_time_config = config
      } else {
        payload.tail_price_time_config = null
      }
    } else {
      payload.ratio_bps = editForm.ratio_bps
      payload.min_usdc = editForm.min_usdc
      payload.max_usdc = editForm.max_usdc
      payload.maker_max_quantity_per_token = editForm.maker_max_quantity_per_token
    }

    console.log('保存编辑 payload:', payload)
    await http.put(`/copy-tasks/${editingTask.value.id}`, payload)
    await store.fetchCopyTasks()
    showSuccessToast('任务已更新')
    showEditDialog.value = false
  } catch (error: any) {
    console.error('保存失败:', error)
    showFailToast(error.response?.data?.msg || error.message || '更新失败')
  } finally {
    saveEditLoading.value = false
  }
}

const loadLeaders = async () => {
  const { data } = await http.get('/leaders')
  leaders.value = data.data.list || []
}

onMounted(() => {
  loadLeaders().catch(() => null)
  store.fetchCopyTasks().catch(() => null)
})
</script>

<template>
  <div class="app-shell">
    <section class="page-head">
      <span class="page-eyebrow">Strategy Builder</span>
      <h1 class="page-title">跟单任务</h1>
      <p class="page-description">配置 leader 跟单或扫尾盘任务，并直接管理当前任务状态。</p>
    </section>

    <section class="surface-card section-card">
      <div class="section-header">
        <div>
          <h2 class="section-title">新建任务</h2>
          <p class="inline-note">可选择 Leader 跟单或扫尾盘模式。</p>
        </div>
        <span class="info-chip info-chip--brand">{{ isLeaderMode ? (form.leader_id ? '已解析 leader' : '等待解析') : (marketResolved ? '已解析市场' : '等待解析') }}</span>
      </div>
      <van-cell-group inset>
        <van-field v-model="form.mode" is-link readonly label="模式" @click="showModeSelect = true">
          <template #input>
            <span>{{ getModeLabel(form.mode) }}</span>
          </template>
        </van-field>
        <template v-if="isLeaderMode">
          <van-field v-model="form.address" label="Leader 地址" placeholder="0x..." />
          <van-field v-model.number="form.ratio_bps" label="比例(bps)" type="number" />
          <van-field v-model.number="form.min_usdc" label="最小USDC" type="number" />
          <van-field v-model.number="form.max_usdc" label="最大USDC" type="number" />
          <van-field v-model="form.maker_max_quantity_per_token" label="Maker单Token最大数量" />
        </template>
        <template v-else>
          <van-field v-model="form.market_input" label="市场链接/Slug" placeholder="https://polymarket.com/... 或 slug" />
          <van-field v-model="form.market_question" label="市场标题" readonly />
          <van-field v-model="form.price_to_beat" label="Price to beat" readonly />
          <van-field v-model.number="form.tail_order_usdc" label="下单金额(USDC)" type="number" />
          <van-field v-model="form.tail_trigger_amount" label="触发阈值" />
          <van-field v-model.number="form.tail_time_limit_seconds" label="限制时间(秒)" type="number" />
          <van-field v-model.number="form.tail_loss_stop_count" label="亏损停止单数" type="number" />
          <div style="padding: 10px 16px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
              <span style="font-size: 14px; color: #646566;">价格-时间配置</span>
              <van-button size="mini" type="primary" @click="form.tail_price_time_config.push({ price: 100, time: 60 })">添加规则</van-button>
            </div>
            <div v-for="(rule, index) in form.tail_price_time_config" :key="index" style="display: flex; gap: 8px; margin-bottom: 8px; align-items: center;">
              <van-field v-model.number="rule.price" placeholder="价格变化" type="number" style="flex: 1;" />
              <span style="color: #969799;">→</span>
              <van-field v-model.number="rule.time" placeholder="时间(秒)" type="number" style="flex: 1;" />
              <van-button size="mini" type="danger" @click="form.tail_price_time_config.splice(index, 1)">删除</van-button>
            </div>
            <div v-if="form.tail_price_time_config.length === 0" style="font-size: 12px; color: #969799; text-align: center; padding: 8px;">
              未配置时使用系统默认规则
            </div>
          </div>
        </template>
      </van-cell-group>
      <div class="actions-stack">
        <van-button
          v-if="isLeaderMode"
          block
          plain
          type="primary"
          :loading="resolvingLeader"
          :disabled="taskActionLoading"
          @click="resolveLeader"
        >
          解析 Leader
        </van-button>
        <van-button
          v-else
          block
          plain
          type="primary"
          :loading="resolvingMarket"
          :disabled="taskActionLoading"
          @click="resolveMarket"
        >
          解析市场
        </van-button>
        <van-button block type="success" :loading="savingTask" :disabled="taskActionLoading" @click="saveTask">保存任务</van-button>
      </div>
    </section>

    <section class="list-section" v-if="isLeaderMode">
      <div class="section-header">
        <h2 class="section-title">已解析 leaders</h2>
        <span class="info-chip">{{ leaders.length }} 个</span>
      </div>
      <van-cell-group v-if="leaders.length" inset>
        <van-cell
          v-for="item in leaders"
          :key="item.id"
          :title="item.display_name || item.proxy_wallet"
          :label="item.proxy_wallet"
          class="address-row"
        />
      </van-cell-group>
      <div v-else class="surface-card empty-state">暂无已解析的 leader，输入地址后即可解析并写入列表。</div>
    </section>

    <section class="list-section">
      <div class="section-header">
        <h2 class="section-title">我的任务</h2>
        <span class="info-chip info-chip--brand">{{ store.copyTasks.length }} 个</span>
      </div>
      <div v-if="store.copyTasks.length" class="task-list">
        <div v-for="item in store.copyTasks" :key="item.id" class="task-card surface-card">
          <div class="task-card__header">
            <div class="task-card__meta">
              <div class="task-card__title">
                {{ item.mode === 'tail_sweep' || item.mode === 'tail_sweep_many'
                  ? (item.market?.question || item.market?.slug || '扫尾盘任务')
                  : (item.leader?.display_name || item.leader?.proxy_wallet) }}
              </div>
              <div class="task-card__address">
                {{ item.mode === 'tail_sweep' || item.mode === 'tail_sweep_many'
                  ? (item.market?.slug || '-')
                  : (item.leader?.proxy_wallet || '-') }}
              </div>
            </div>
            <span class="info-chip" :class="item.status === 1 ? 'info-chip--success' : 'info-chip--warning'">
              {{ item.status === 1 ? '启用' : '暂停' }}
            </span>
          </div>
          <div class="task-card__detail" v-if="item.mode === 'tail_sweep' || item.mode === 'tail_sweep_many'">
            {{ item.mode === 'tail_sweep_many' ? '扫尾盘(多单)' : '扫尾盘(单单)' }} / 金额={{ item.tail_order_usdc }} / 阈值={{ item.tail_trigger_amount }} / 时间={{ item.tail_time_limit_seconds }}秒 / 已亏损={{ item.tail_loss_count }}/{{ item.tail_loss_stop_count }}
          </div>
          <div class="task-card__detail" v-else>
            跟单 / ratio={{ item.ratio_bps }}, min={{ item.min_usdc }}, max={{ item.max_usdc }}, maker上限={{ item.maker_max_quantity_per_token || '不限' }}
          </div>
          <div class="task-card__actions">
            <van-button
              size="small"
              plain
              type="primary"
              :loading="togglingTaskId === item.id"
              :disabled="togglingTaskId !== null || deletingTaskId !== null"
              @click="toggleTask(item)"
            >
              {{ item.status === 1 ? '暂停任务' : '恢复任务' }}
            </van-button>
            <van-button
              size="small"
              type="success"
              @click="openEditDialog(item)"
            >
              编辑
            </van-button>
            <van-button
              size="small"
              type="danger"
              :loading="deletingTaskId === item.id"
              :disabled="togglingTaskId !== null || (deletingTaskId !== null && deletingTaskId !== item.id)"
              @click="removeTask(item)"
            >
              删除
            </van-button>
          </div>
        </div>
      </div>
      <div v-else class="surface-card empty-state">暂无任务，创建成功后可在此点击切换启用或暂停状态。</div>
    </section>

    <van-dialog v-model:show="showEditDialog" title="编辑任务" :show-confirm-button="false" :show-cancel-button="false">
      <van-cell-group inset style="margin: 16px 0;">
        <template v-if="editingTask?.mode === 'tail_sweep' || editingTask?.mode === 'tail_sweep_many'">
          <van-field v-model.number="editForm.tail_order_usdc" label="下单金额(USDC)" type="number" />
          <van-field v-model="editForm.tail_trigger_amount" label="触发阈值" />
          <van-field v-model.number="editForm.tail_time_limit_seconds" label="限制时间(秒)" type="number" />
          <van-field v-model.number="editForm.tail_loss_stop_count" label="亏损停止单数" type="number" />
          <div style="padding: 10px 16px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
              <span style="font-size: 14px; color: #646566;">价格-时间配置</span>
              <van-button size="mini" type="primary" @click="addPriceTimeRule">添加规则</van-button>
            </div>
            <div v-for="(rule, index) in editForm.tail_price_time_config" :key="index" style="display: flex; gap: 8px; margin-bottom: 8px; align-items: center;">
              <van-field v-model.number="rule.price" placeholder="价格变化" type="number" style="flex: 1;" />
              <span style="color: #969799;">→</span>
              <van-field v-model.number="rule.time" placeholder="时间(秒)" type="number" style="flex: 1;" />
              <van-button size="mini" type="danger" @click="removePriceTimeRule(index)">删除</van-button>
            </div>
            <div v-if="editForm.tail_price_time_config.length === 0" style="font-size: 12px; color: #969799; text-align: center; padding: 8px;">
              未配置时使用系统默认规则
            </div>
          </div>
        </template>
        <template v-else>
          <van-field v-model.number="editForm.ratio_bps" label="比例(bps)" type="number" />
          <van-field v-model.number="editForm.min_usdc" label="最小USDC" type="number" />
          <van-field v-model.number="editForm.max_usdc" label="最大USDC" type="number" />
          <van-field v-model="editForm.maker_max_quantity_per_token" label="Maker单Token最大数量" />
        </template>
      </van-cell-group>
      <div style="display: flex; gap: 12px; padding: 0 16px 16px;">
        <van-button block plain @click="showEditDialog = false">取消</van-button>
        <van-button block type="primary" :loading="saveEditLoading" @click="saveEdit">保存</van-button>
      </div>
    </van-dialog>

    <van-action-sheet v-model:show="showModeSelect" :actions="modeOptions" @select="(item: any) => { form.mode = item.value; showModeSelect = false }" />
  </div>
</template>

<style scoped>
.section-card,
.list-section {
  margin-bottom: 18px;
}

.section-card {
  display: grid;
  gap: 14px;
  padding: 18px;
}

.section-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 12px;
}

.task-list {
  display: grid;
  gap: 12px;
}

.task-card {
  padding: 16px;
}

.task-card__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.task-card__meta {
  min-width: 0;
}

.task-card__title {
  font-size: 15px;
  font-weight: 700;
  line-height: 1.5;
  word-break: break-word;
}

.task-card__address,
.task-card__detail {
  margin-top: 6px;
  color: var(--text-tertiary);
  font-size: 12px;
  line-height: 1.7;
  word-break: break-all;
}

.task-card__actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 14px;
}

.list-section :deep(.van-cell__label),
.address-row :deep(.van-cell__label),
.address-row :deep(.van-cell__title) {
  word-break: break-all;
}
</style>
