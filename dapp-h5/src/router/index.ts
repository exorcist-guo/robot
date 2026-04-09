import { createRouter, createWebHistory } from 'vue-router'
import LoginPage from '../pages/LoginPage.vue'
import HomePage from '../pages/HomePage.vue'
import CommunityPage from '../pages/CommunityPage.vue'
import MePage from '../pages/MePage.vue'
import CopyTasksPage from '../pages/CopyTasksPage.vue'
import RecordsPage from '../pages/RecordsPage.vue'
import RecordDetailPage from '../pages/RecordDetailPage.vue'
import TriggerStatsPage from '../pages/TriggerStatsPage.vue'
import MainLayout from '../layouts/MainLayout.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', component: LoginPage },
    {
      path: '/',
      component: MainLayout,
      children: [
        { path: '', redirect: '/home' },
        { path: '/home', component: HomePage },
        { path: '/community', component: CommunityPage },
        { path: '/me', component: MePage },
      ],
    },
    { path: '/copy-tasks', component: CopyTasksPage },
    { path: '/records', component: RecordsPage },
    { path: '/records/:id', component: RecordDetailPage },
    { path: '/trigger-stats', component: TriggerStatsPage },
  ],
})

router.beforeEach((to) => {
  const token = localStorage.getItem('token')
  if (to.path !== '/login' && !token) {
    return '/login'
  }
  if (to.path === '/login' && token) {
    return '/home'
  }
})

export default router
