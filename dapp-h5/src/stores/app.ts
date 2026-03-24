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
  },
})
