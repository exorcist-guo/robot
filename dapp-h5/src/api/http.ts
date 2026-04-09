import axios from 'axios'
import type { ApiResponse } from '../types/api'

const http = axios.create({
  baseURL: 'https://dhbapi.88888888.mom/api',
  timeout: 15000,
})

http.interceptors.request.use((config) => {
  const token = localStorage.getItem('token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

http.interceptors.response.use((response) => {
  const data = response.data as ApiResponse
  if (typeof data?.code === 'number' && data.code !== 0) {
    return Promise.reject(new Error(data.msg || '请求失败'))
  }
  return response
})

export default http
