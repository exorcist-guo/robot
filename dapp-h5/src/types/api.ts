export interface ApiResponse<T = any> {
  msg: string
  code: number
  data: T
}
