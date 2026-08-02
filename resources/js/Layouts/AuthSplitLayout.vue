<script setup>
// Shared animated split-screen shell for all auth pages (login / register / forgot).
// The left brand scene + animations live here; each page supplies its form via the slot.
const particles = [
    { id: 1, left: '6%', size: '5px', delay: '0s', duration: '15s' },
    { id: 2, left: '14%', size: '8px', delay: '3s', duration: '19s' },
    { id: 3, left: '22%', size: '4px', delay: '6s', duration: '13s' },
    { id: 4, left: '31%', size: '7px', delay: '1.5s', duration: '17s' },
    { id: 5, left: '39%', size: '5px', delay: '8s', duration: '21s' },
    { id: 6, left: '47%', size: '3px', delay: '4s', duration: '12s' },
    { id: 7, left: '55%', size: '9px', delay: '2s', duration: '20s' },
    { id: 8, left: '63%', size: '4px', delay: '7s', duration: '16s' },
    { id: 9, left: '71%', size: '6px', delay: '5s', duration: '18s' },
    { id: 10, left: '79%', size: '5px', delay: '9s', duration: '14s' },
    { id: 11, left: '86%', size: '7px', delay: '2.5s', duration: '22s' },
    { id: 12, left: '93%', size: '4px', delay: '6.5s', duration: '15s' },
];
</script>

<template>
    <div class="flex min-h-screen bg-white">
        <!-- ================= LEFT — animated brand scene ================= -->
        <div class="brand relative hidden w-[55%] flex-col justify-between overflow-hidden p-10 lg:flex xl:p-14">
            <div class="pointer-events-none absolute inset-0" aria-hidden="true">
                <div class="aurora aurora-1"></div>
                <div class="aurora aurora-2"></div>
                <div class="aurora aurora-3"></div>
            </div>
            <div class="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
                <span class="blob absolute -left-24 -top-28 h-96 w-96 rounded-full bg-white/10" style="animation-delay: 0s"></span>
                <span class="blob absolute -bottom-20 -right-16 h-80 w-80 rounded-full bg-emerald-300/10" style="animation-delay: 3s"></span>
            </div>
            <div class="pointer-events-none absolute inset-0 opacity-[0.07] [background-image:linear-gradient(#fff_1px,transparent_1px),linear-gradient(90deg,#fff_1px,transparent_1px)] [background-size:44px_44px]" aria-hidden="true"></div>
            <div class="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
                <span v-for="p in particles" :key="p.id" class="particle"
                    :style="{ left: p.left, width: p.size, height: p.size, animationDelay: p.delay, animationDuration: p.duration }"></span>
            </div>

            <!-- Logo -->
            <div class="auth-reveal relative flex w-max items-center gap-3 rounded-2xl border border-white/15 bg-white/10 px-4 py-3 backdrop-blur-md" style="animation-delay: 0ms">
                <span class="flex h-10 w-10 items-center justify-center overflow-hidden rounded-xl shadow-sm">
                    <img src="/logobaiagolding.jpg" alt="BAIA Holding" class="h-full w-full object-cover" />
                </span>
                <div class="leading-tight">
                    <div class="text-sm font-bold text-white">BAIA</div>
                    <div class="text-[11px] font-medium text-emerald-200">ERP · CRM</div>
                </div>
            </div>

            <!-- Center content -->
            <div class="relative max-w-lg">
                <h1 class="auth-reveal text-5xl font-extrabold leading-tight tracking-tight text-white xl:text-6xl" style="animation-delay: 100ms">
                    Управление<br />
                    <span class="shine-text bg-gradient-to-r from-emerald-200 via-white to-emerald-300 bg-clip-text text-transparent">вашим бизнесом</span>
                </h1>
                <p class="auth-reveal mt-6 max-w-md text-base leading-relaxed text-emerald-50/80" style="animation-delay: 200ms">
                    Сделки, финансы, цех и аналитика холдинга — в одной системе. Планы и факты в реальном времени.
                </p>

                <div class="auth-reveal mt-10 grid grid-cols-3 gap-4" style="animation-delay: 300ms">
                    <div v-for="s in [
                            { v: '8', l: 'Модулей', live: false },
                            { v: 'Live', l: 'Аналитика', live: true },
                            { v: '24/7', l: 'Доступ', live: false },
                        ]" :key="s.l"
                        class="rounded-2xl border border-white/15 bg-white/10 p-4 backdrop-blur-md transition-all duration-300 hover:-translate-y-1 hover:border-white/25 hover:bg-white/15">
                        <div class="flex items-center gap-1.5 text-2xl font-bold text-white">
                            <span v-if="s.live" class="live-dot h-2 w-2 rounded-full bg-emerald-300"></span>{{ s.v }}
                        </div>
                        <div class="mt-1 text-xs font-medium text-emerald-100/70">{{ s.l }}</div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="auth-reveal relative flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs text-emerald-100/70" style="animation-delay: 500ms">
                <span>© 2026 <span class="font-semibold text-white">BAIA Holding</span> · Разработано</span>
                <a href="https://instagram.com/baproger.kz" target="_blank" rel="noopener noreferrer"
                    class="group inline-flex items-center gap-1.5 font-semibold text-white transition-colors hover:text-emerald-200">
                    <svg viewBox="0 0 24 24" class="h-4 w-4 transition-transform group-hover:scale-110" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="3.5"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"/></svg>
                    baProger.kz
                </a>
            </div>
        </div>

        <!-- ================= RIGHT — form slot ================= -->
        <div class="flex w-full items-center justify-center px-6 py-12 sm:px-10 lg:w-[45%]">
            <div class="auth-reveal w-full max-w-md" style="animation-delay: 150ms">
                <!-- Mobile logo -->
                <div class="mb-8 flex items-center gap-2.5 lg:hidden">
                    <span class="flex h-10 w-10 items-center justify-center overflow-hidden rounded-xl">
                        <img src="/logobaiagolding.jpg" alt="BAIA Holding" class="h-full w-full object-cover" />
                    </span>
                    <div class="leading-tight">
                        <div class="text-sm font-bold text-slate-900">BAIA</div>
                        <div class="text-[11px] font-medium text-emerald-600">ERP · CRM</div>
                    </div>
                </div>

                <slot />
            </div>
        </div>
    </div>
</template>

<!-- Left-scene animations (scoped to this layout's markup) -->
<style scoped>
.brand {
    background: linear-gradient(125deg, #059669, #047857, #065f46, #0f766e, #064e3b);
    background-size: 300% 300%;
    animation: gradient-shift 16s ease infinite;
}
@keyframes gradient-shift {
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}
.aurora { position: absolute; border-radius: 9999px; filter: blur(70px); mix-blend-mode: screen; opacity: 0.55; }
.aurora-1 { top: -10%; left: -5%; height: 420px; width: 420px; background: radial-gradient(circle, #6ee7b7, transparent 70%); animation: drift1 22s ease-in-out infinite; }
.aurora-2 { bottom: -15%; right: -5%; height: 480px; width: 480px; background: radial-gradient(circle, #2dd4bf, transparent 70%); animation: drift2 26s ease-in-out infinite; }
.aurora-3 { top: 30%; right: 20%; height: 320px; width: 320px; background: radial-gradient(circle, #a7f3d0, transparent 70%); animation: drift3 30s ease-in-out infinite; }
@keyframes drift1 { 0%,100% { transform: translate(0,0) scale(1); } 50% { transform: translate(60px, 40px) scale(1.15); } }
@keyframes drift2 { 0%,100% { transform: translate(0,0) scale(1); } 50% { transform: translate(-70px, -50px) scale(1.2); } }
@keyframes drift3 { 0%,100% { transform: translate(0,0) scale(1); } 50% { transform: translate(-40px, 60px) scale(0.9); } }
.blob { animation: float 14s ease-in-out infinite; filter: blur(6px); }
@keyframes float { 0%,100% { transform: translate(0,0) scale(1); } 50% { transform: translate(20px,-24px) scale(1.06); } }
.particle {
    position: absolute; bottom: -12px; border-radius: 9999px;
    background: rgba(255,255,255,0.55); box-shadow: 0 0 8px rgba(255,255,255,0.5);
    animation-name: rise; animation-timing-function: linear; animation-iteration-count: infinite;
}
@keyframes rise {
    0% { transform: translateY(0) scale(1); opacity: 0; }
    10% { opacity: 0.7; } 90% { opacity: 0.4; }
    100% { transform: translateY(-108vh) scale(0.4); opacity: 0; }
}
.shine-text { background-size: 200% auto; animation: shine 5s linear infinite; }
@keyframes shine { to { background-position: 200% center; } }
.live-dot { animation: pulse-dot 1.6s ease-in-out infinite; }
@keyframes pulse-dot {
    0%,100% { box-shadow: 0 0 0 0 rgba(110,231,183,0.7); }
    50% { box-shadow: 0 0 0 6px rgba(110,231,183,0); }
}
@media (prefers-reduced-motion: reduce) {
    .brand, .aurora, .blob, .particle, .shine-text, .live-dot { animation: none !important; }
}
</style>

<!-- Shared helpers usable by slotted forms (global, namespaced auth-*) -->
<style>
.auth-reveal { opacity: 0; animation: authReveal 0.7s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
@keyframes authReveal { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }

.auth-input {
    width: 100%; border-radius: 0.75rem; border: 1px solid #e2e8f0; background: #f8fafc;
    font-size: 0.875rem; color: #0f172a; transition: all 0.2s;
}
.auth-input::placeholder { color: #94a3b8; }
.auth-input:focus { outline: none; border-color: #10b981; background: #fff; box-shadow: 0 0 0 4px rgba(16,185,129,0.12); }
.auth-ico { transition: color 0.2s; }
.group:focus-within .auth-ico { color: #10b981; }

.auth-btn { position: relative; overflow: hidden; animation: authGlow 2.6s ease-in-out infinite; }
.auth-btn::after {
    content: ''; position: absolute; inset: 0;
    background: linear-gradient(120deg, transparent 30%, rgba(255,255,255,0.35) 50%, transparent 70%);
    transform: translateX(-130%); animation: authSheen 3.5s ease-in-out infinite;
}
@keyframes authGlow {
    0%,100% { box-shadow: 0 10px 25px -5px rgba(16,185,129,0.45); }
    50% { box-shadow: 0 10px 40px -3px rgba(16,185,129,0.75); }
}
@keyframes authSheen { 0%,60% { transform: translateX(-130%); } 100% { transform: translateX(130%); } }

/* A button that both reveals and glows needs BOTH animations declared together,
   otherwise one `animation` shorthand overrides the other (leaving opacity: 0). */
.auth-reveal.auth-btn {
    animation: authReveal 0.7s cubic-bezier(0.16, 1, 0.3, 1) forwards, authGlow 2.6s ease-in-out infinite;
}

@media (prefers-reduced-motion: reduce) {
    .auth-reveal { opacity: 1 !important; animation: none !important; }
    .auth-btn, .auth-btn::after { animation: none !important; }
}
</style>
