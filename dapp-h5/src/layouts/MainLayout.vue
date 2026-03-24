<template>
  <div class="layout-shell">
    <main class="layout-content">
      <router-view />
    </main>
    <van-tabbar route>
      <van-tabbar-item replace to="/home" icon="home-o">首页</van-tabbar-item>
      <van-tabbar-item replace to="/community" icon="friends-o">社区</van-tabbar-item>
      <van-tabbar-item replace to="/me" icon="manager-o">我的</van-tabbar-item>
    </van-tabbar>
  </div>
</template>

<style scoped>
.layout-shell {
  min-height: 100vh;
}

.layout-content {
  min-height: 100vh;
}
</style>
