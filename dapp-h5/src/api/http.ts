import axios from 'axios'
import type { ApiResponse } from '../types/api'

const http = axios.create({
  baseURL: 'https://dhbapi.88888888.mom/api',
  // baseURL: '  http://test.robot.xyz/api',
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
    const error: any = new Error(data.msg || '请求失败')
    error.response = response
    return Promise.reject(error)
  }
  return response
})

export default http
