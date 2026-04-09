import { defineStore } from 'pinia'
import http from '../api/http'

export const useAppStore = defineStore('app', {
  state: () => ({
    home: null as any,
    me: null as any,
    community: null as any,
    walletStatus: null as any,
    walletAllowanceStatus: null as any,
    copyTasks: [] as any[],
    recordsList: [] as any[],
    recordsTotal: 0,
    recordsPage: 1,
    recordsLimit: 20,
    recordsLoading: false,
    recordsFinished: false,
    recordDetail: null as any,
    recordsStatsByTrigger: [] as any[],
  }),
  actions: {
    async fetchHome() {
      const { data } = await http.get('/home')
      this.home = data.data
      return this.home
    },
    async fetchMe() {
      const { data } = await http.get('/me')
      this.me = data.data
      return this.me
    },
    async fetchCommunity() {
      const [summaryRes, recordsRes] = await Promise.all([
        http.get('/community/summary'),
        http.get('/community/records'),
      ])
      this.community = {
        summary: summaryRes.data.data,
        records: recordsRes.data.data.list,
      }
      return this.community
    },
    async fetchWalletStatus() {
      const { data } = await http.get('/wallet/status')
      this.walletStatus = data.data
      return this.walletStatus
    },
    async fetchWalletAllowanceStatus(params?: Record<string, any>) {
      const { data } = await http.get('/wallet/allowance-status', { params })
      this.walletAllowanceStatus = data.data
      return this.walletAllowanceStatus
    },
    async fetchCopyTasks() {
      const { data } = await http.get('/copy-tasks')
      this.copyTasks = data.data.list || []
      return this.copyTasks
    },
    resetRecords(limit?: number) {
      this.recordsList = []
      this.recordsTotal = 0
      this.recordsPage = 1
      this.recordsLimit = limit || 20
      this.recordsLoading = false
      this.recordsFinished = false
    },
    async fetchRecords(params?: { page?: number; limit?: number; reset?: boolean }) {
      const reset = !!params?.reset
      const page = params?.page ?? (reset ? 1 : this.recordsPage)
      const limit = params?.limit ?? this.recordsLimit

      if (this.recordsLoading) {
        return {
          count: this.recordsTotal,
          list: this.recordsList,
          page: this.recordsPage,
          limit: this.recordsLimit,
          has_more: !this.recordsFinished,
        }
      }

      if (reset) {
        this.resetRecords(limit)
      }

      this.recordsLoading = true
      try {
        const { data } = await http.get('/me/records', { params: { page, limit } })
        const payload = data.data || {}
        const list = Array.isArray(payload.list) ? payload.list : []
        const currentList = reset ? [] : this.recordsList
        const merged = [...currentList, ...list]
        const uniqueList = merged.filter((item, index, array) => array.findIndex((row) => row.id === item.id) === index)

        this.recordsList = uniqueList
        this.recordsTotal = Number(payload.count || 0)
        this.recordsPage = Number(payload.page || page) + 1
        this.recordsLimit = Number(payload.limit || limit)
        this.recordsFinished = payload.has_more === false || uniqueList.length >= this.recordsTotal || list.length < this.recordsLimit

        return payload
      } finally {
        this.recordsLoading = false
      }
    },
    async fetchRecordDetail(id: string | number) {
      const { data } = await http.get(`/me/records/${id}`)
      this.recordDetail = data.data
      return this.recordDetail
    },
    resetRecordDetail() {
      this.recordDetail = null
    },
    async fetchRecordsStatsByTrigger() {
      const { data } = await http.get('/me/records-stats/by-trigger')
      this.recordsStatsByTrigger = data.data?.stats || []
      return this.recordsStatsByTrigger
    },
  },
})
