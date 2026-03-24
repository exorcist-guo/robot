interface EthereumProvider {
  request: (args: { method: string; params?: any[] | Record<string, any> }) => Promise<any>
}

declare global {
  interface Window {
    ethereum?: EthereumProvider
  }
}

export {}
