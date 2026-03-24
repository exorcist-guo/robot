<script setup lang="ts">
import { onMounted } from 'vue'
import { useAppStore } from '../stores/app'

const store = useAppStore()

onMounted(() => {
  store.fetchCommunity().catch(() => null)
})
</script>

<template>
  <div class="app-shell">
    <section class="page-head">
      <span class="page-eyebrow">Community Relay</span>
      <h1 class="page-title">社区</h1>
      <p class="page-description">跟踪邀请增长、团队扩展与最近的推荐记录。</p>
    </section>

    <section class="summary-card glass-card">
      <div class="summary-grid">
        <div class="metric-card">
          <div class="metric-label">直推人数</div>
          <div class="metric-value">{{ store.community?.summary?.invite_count || 0 }}</div>
        </div>
        <div class="metric-card">
          <div class="metric-label">团队人数</div>
          <div class="metric-value metric-value--accent">{{ store.community?.summary?.team_count || 0 }}</div>
        </div>
      </div>
      <div class="invite-card surface-card surface-card--muted">
        <div class="section-title">邀请链接</div>
        <div class="invite-url">{{ store.community?.summary?.invite_url || '-' }}</div>
      </div>
    </section>

    <section>
      <div class="section-header">
        <h2 class="section-title">推荐记录</h2>
        <span class="info-chip">{{ store.community?.records?.length || 0 }} 条</span>
      </div>
      <van-cell-group v-if="store.community?.records?.length" inset>
        <van-cell
          v-for="item in store.community?.records || []"
          :key="item.id"
          :title="item.nickname || item.address"
          :label="item.created_at"
        />
      </van-cell-group>
      <div v-else class="surface-card empty-state">暂无推荐记录，新的邀请关系会在这里汇总展示。</div>
    </section>
  </div>
</template>

<style scoped>
.summary-card {
  display: grid;
  gap: 14px;
  padding: 18px;
  margin-bottom: 18px;
}

.summary-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.invite-card {
  padding: 16px;
  border-radius: var(--radius-md);
  border: 1px solid rgba(15, 118, 110, 0.12);
  box-shadow: none;
}

.invite-url {
  color: var(--text-secondary);
  font-size: 13px;
  line-height: 1.7;
  word-break: break-all;
}

.section-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 12px;
}
</style>
