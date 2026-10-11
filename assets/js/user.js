/**
 * SMM Panel - User Portal Vanilla JavaScript (ES6+)
 * 3D Card Carousel with Touch & Mouse Gestures, Perspective Animation,
 * Card Full-Screen Expansion Transition, and Clipboard Helpers
 */

function cardCarousel(initialItems) {
    const defaultItems = [
        {
            id: 'new-order',
            title: 'New Order',
            subtitle: 'Place a new order for your social media',
            badge: 'Instant Delivery',
            icon: '⚡',
            bgClass: 'from-blue-600 via-indigo-600 to-purple-600',
            glow: 'rgba(79, 70, 229, 0.55)',
            link: '/user/new-order.php',
            action: 'Open →'
        },
        {
            id: 'mass-order',
            title: 'Mass Order',
            subtitle: 'Bulk submit multiple links & quantities',
            badge: 'Batch Engine',
            icon: '📚',
            bgClass: 'from-violet-600 via-purple-600 to-indigo-700',
            glow: 'rgba(124, 58, 237, 0.50)',
            link: '/user/mass-order.php',
            action: 'Open →'
        },
        {
            id: 'drip-feed',
            title: 'Drip-feed Order',
            subtitle: 'Scheduled interval delivery across runs',
            badge: 'Gradual Growth',
            icon: '💧',
            bgClass: 'from-cyan-600 via-teal-600 to-emerald-600',
            glow: 'rgba(13, 148, 136, 0.50)',
            link: '/user/drip-feed.php',
            action: 'Open →'
        },
        {
            id: 'add-funds',
            title: 'Add Funds',
            subtitle: 'Top up your account wallet balance',
            badge: 'Instant Wallet',
            icon: '💳',
            bgClass: 'from-emerald-500 via-green-500 to-teal-600',
            glow: 'rgba(16, 185, 129, 0.45)',
            link: '/user/add-funds.php',
            action: 'Open →'
        },
        {
            id: 'my-orders',
            title: 'My Orders',
            subtitle: 'Track your live order statuses',
            badge: 'Live Status',
            icon: '📦',
            bgClass: 'from-amber-500 via-orange-500 to-amber-600',
            glow: 'rgba(245, 158, 11, 0.45)',
            link: '/user/orders.php',
            action: 'Open →'

        },
        {
            id: 'refer-earn',
            title: 'Refer & Earn',
            subtitle: 'Invite friends & earn lifetime commissions',
            badge: 'Lifetime Payout',
            icon: '🎁',
            bgClass: 'from-rose-500 via-pink-600 to-fuchsia-600',
            glow: 'rgba(236, 72, 153, 0.50)',
            link: '/user/referrals.php',
            action: 'Open →'
        }
    ];

    const cardList = (Array.isArray(initialItems) && initialItems.length) ? initialItems : defaultItems;

    return {
        active: 0, // 'New Order' (index 0) is the prominent center card by default
        startX: 0,
        startY: 0,
        startTime: 0,
        isDragging: false,
        dragMoved: false,
        isMobile: false,
        isExpanding: false,
        items: cardList,

        init() {
            this.checkMobile();
            let resizeRaf = null;
            window.addEventListener('resize', () => {
                if (!resizeRaf) {
                    resizeRaf = requestAnimationFrame(() => {
                        this.checkMobile();
                        resizeRaf = null;
                    });
                }
            }, { passive: true });
            window.addEventListener('keydown', (e) => {
                if (e.key === 'ArrowLeft') this.prev();
                if (e.key === 'ArrowRight') this.next();
                if (e.key === 'Enter') {
                    const current = this.items[this.active];
                    if (current) this.expandCard(this.active, current.link);
                }
            });
        },

        checkMobile() {
            this.isMobile = window.innerWidth < 768;
        },

        next() {
            if (this.isExpanding) return;
            this.active = (this.active + 1) % this.items.length;
        },

        prev() {
            if (this.isExpanding) return;
            this.active = (this.active - 1 + this.items.length) % this.items.length;
        },

        goTo(idx) {
            if (this.isExpanding) return;
            this.active = idx;
        },

        getDiff(idx) {
            let diff = idx - this.active;
            const len = this.items.length;
            if (diff > len / 2) diff -= len;
            if (diff < -len / 2) diff += len;
            return diff;
        },

        getCardStyle(idx) {
            const diff = this.getDiff(idx);
            const mobile = this.isMobile;

            if (diff === 0) {
                // Active Center Card: Front-facing, scale 1, no rotation, prominent elevation
                return {
                    transform: 'translate3d(0, 0, 0) scale(1) rotateY(0deg)',
                    opacity: '1',
                    zIndex: '30',
                    filter: 'brightness(1)',
                    pointerEvents: 'auto',
                    boxShadow: '0 25px 60px -15px rgba(59, 130, 246, 0.45)',
                    willChange: 'transform, opacity',
                    backfaceVisibility: 'hidden',
                    WebkitBackfaceVisibility: 'hidden'
                };
            }
            if (diff === -1) {
                // Left Tilted Card: rotated into 3D space, partially visible
                const tx = mobile ? '-52%' : '-58%';
                const rot = mobile ? '24deg' : '26deg';
                const sc = mobile ? '0.84' : '0.86';
                const tz = mobile ? '-40px' : '-60px';
                return {
                    transform: `translate3d(${tx}, 0, ${tz}) scale(${sc}) rotateY(${rot})`,
                    opacity: '0.92',
                    zIndex: '20',
                    filter: 'brightness(0.95)',
                    cursor: 'pointer',
                    pointerEvents: 'auto',
                    boxShadow: '0 20px 45px -12px rgba(16, 185, 129, 0.35)',
                    willChange: 'transform, opacity',
                    backfaceVisibility: 'hidden',
                    WebkitBackfaceVisibility: 'hidden'
                };
            }
            if (diff === 1) {
                // Right Tilted Card: angled in reverse perspective
                const tx = mobile ? '52%' : '58%';
                const rot = mobile ? '-24deg' : '-26deg';
                const sc = mobile ? '0.84' : '0.86';
                const tz = mobile ? '-40px' : '-60px';
                return {
                    transform: `translate3d(${tx}, 0, ${tz}) scale(${sc}) rotateY(${rot})`,
                    opacity: '0.92',
                    zIndex: '20',
                    filter: 'brightness(0.95)',
                    cursor: 'pointer',
                    pointerEvents: 'auto',
                    boxShadow: '0 20px 45px -12px rgba(245, 158, 11, 0.35)',
                    willChange: 'transform, opacity',
                    backfaceVisibility: 'hidden',
                    WebkitBackfaceVisibility: 'hidden'
                };
            }
            if (diff === -2) {
                // Outer Left Card peek (stacked layer)
                const tx = mobile ? '-86%' : '-92%';
                return {
                    transform: `translate3d(${tx}, 0, -110px) scale(0.72) rotateY(32deg)`,
                    opacity: '0.45',
                    zIndex: '10',
                    filter: 'brightness(0.85) blur(0.5px)',
                    pointerEvents: 'none',
                    willChange: 'transform, opacity',
                    backfaceVisibility: 'hidden',
                    WebkitBackfaceVisibility: 'hidden'
                };
            }
            if (diff === 2) {
                // Outer Right Card peek (stacked layer)
                const tx = mobile ? '86%' : '92%';
                return {
                    transform: `translate3d(${tx}, 0, -110px) scale(0.72) rotateY(-32deg)`,
                    opacity: '0.45',
                    zIndex: '10',
                    filter: 'brightness(0.85) blur(0.5px)',
                    pointerEvents: 'none',
                    willChange: 'transform, opacity',
                    backfaceVisibility: 'hidden',
                    WebkitBackfaceVisibility: 'hidden'
                };
            }
            // Distant cards
            return {
                transform: 'translate3d(0, 0, -200px) scale(0.5)',
                opacity: '0',
                zIndex: '5',
                pointerEvents: 'none',
                willChange: 'transform, opacity',
                backfaceVisibility: 'hidden',
                WebkitBackfaceVisibility: 'hidden'
            };
        },

        cardClick(event, idx, link) {
            if (this.dragMoved || this.isExpanding) return;
            const diff = this.getDiff(idx);
            if (diff === 0) {
                // Active Center Card: trigger smooth expansion toward viewport
                const cardEl = event.currentTarget;
                this.expandCard(idx, link, cardEl);
            } else {
                // Clicking side card rotates it to center
                this.goTo(idx);
            }
        },

        /**
         * Smooth Full-Screen Card Expansion Animation
         * Starts from the exact card position and scales smoothly to fill the viewport
         */
        expandCard(idx, link, cardEl) {
            if (this.isExpanding) return;
            this.isExpanding = true;

            const item = this.items[idx];
            const el = cardEl || document.querySelector(`[data-card-index="${idx}"]`);
            const rect = el ? el.getBoundingClientRect() : { top: 100, left: 20, width: 320, height: 280 };

            // Create temporary expansion clone
            const overlay = document.createElement('div');
            overlay.id = 'fullCardExpander';
            overlay.className = `fixed z-50 text-white rounded-3xl p-6 sm:p-7 flex flex-col justify-between overflow-hidden shadow-2xl bg-gradient-to-br ${item.bgClass}`;
            overlay.style.position = 'fixed';
            overlay.style.top = `${rect.top}px`;
            overlay.style.left = `${rect.left}px`;
            overlay.style.width = `${rect.width}px`;
            overlay.style.height = `${rect.height}px`;
            overlay.style.transformOrigin = 'center center';
            overlay.style.transform = 'translateZ(0)';
            overlay.style.backfaceVisibility = 'hidden';
            overlay.style.webkitBackfaceVisibility = 'hidden';
            overlay.style.pointerEvents = 'none';
            overlay.style.willChange = 'top, left, width, height, border-radius, box-shadow';
            overlay.style.transition = 'top 360ms cubic-bezier(0.16, 1, 0.3, 1), left 360ms cubic-bezier(0.16, 1, 0.3, 1), width 360ms cubic-bezier(0.16, 1, 0.3, 1), height 360ms cubic-bezier(0.16, 1, 0.3, 1), border-radius 360ms cubic-bezier(0.16, 1, 0.3, 1), box-shadow 360ms cubic-bezier(0.16, 1, 0.3, 1)';
            overlay.style.boxShadow = `0 30px 60px -15px ${item.glow || 'rgba(0,0,0,0.5)'}`;

            overlay.innerHTML = `
                <div class="flex items-start justify-between">
                    <div class="w-14 h-14 rounded-2xl bg-white/20 backdrop-blur-md flex items-center justify-center text-3xl shadow-inner">${item.icon}</div>
                    <span class="px-3 py-1 rounded-full bg-white/20 backdrop-blur-md text-[11px] font-bold tracking-wide">${item.badge}</span>
                </div>
                <div>
                    <h2 class="text-3xl sm:text-4xl font-black tracking-tight">${item.title}</h2>
                    <p class="text-xs sm:text-sm text-white/90 mt-1 font-medium">${item.subtitle}</p>
                </div>
                <div class="flex items-center justify-between text-xs sm:text-sm font-bold text-white pt-2 border-t border-white/15">
                    <span>Loading...</span>
                    <span class="text-lg animate-pulse">&rarr;</span>
                </div>
            `;

            document.body.appendChild(overlay);

            if (el) {
                el.style.opacity = '0.35';
            }

            // Animate expansion to viewport in the next frame
            requestAnimationFrame(() => {
                overlay.style.top = '0px';
                overlay.style.left = '0px';
                overlay.style.width = '100vw';
                overlay.style.height = '100vh';
                overlay.style.borderRadius = '0px';
            });

            // Navigate when expansion completes
            setTimeout(() => {
                window.location.assign(link);
            }, 360);
        },

        // Touch & Swipe handlers (distinguishing between swipe and tap gestures)
        touchStart(e) {
            this.startX = e.touches[0].clientX;
            this.startY = e.touches[0].clientY;
            this.startTime = Date.now();
            this.dragMoved = false;
        },

        touchMove(e) {
            if (!this.startX) return;
            const currentX = e.touches[0].clientX;
            const currentY = e.touches[0].clientY;
            const diffX = currentX - this.startX;
            const diffY = currentY - this.startY;

            // Only capture intentional horizontal swipes
            if (Math.abs(diffX) > Math.abs(diffY) && Math.abs(diffX) > 12) {
                if (e.cancelable) e.preventDefault();
                this.dragMoved = true;
            }
        },

        touchEnd(e) {
            if (!this.startX) return;
            const endX = e.changedTouches[0].clientX;
            const diffX = endX - this.startX;
            const elapsed = Date.now() - this.startTime;

            if (this.dragMoved && Math.abs(diffX) > 35) {
                if (diffX < 0) {
                    this.next();
                } else {
                    this.prev();
                }
            }
            
            this.startX = 0;
            this.startY = 0;
            setTimeout(() => { this.dragMoved = false; }, 120);
        },

        // Mouse Drag handlers for desktop
        dragStart(e) {
            // Ignore if clicking a button
            if (e.target.closest('button')) return;
            this.startX = e.clientX;
            this.isDragging = true;
            this.dragMoved = false;
        },

        dragMove(e) {
            if (!this.isDragging || this.dragMoved) return;
            const diffX = e.clientX - this.startX;
            if (Math.abs(diffX) > 10) {
                this.dragMoved = true;
            }
        },

        dragEnd(e) {
            if (!this.isDragging) return;
            const diffX = e.clientX - this.startX;
            if (Math.abs(diffX) > 40) {
                if (diffX < 0) {
                    this.next();
                } else {
                    this.prev();
                }
            }
            this.isDragging = false;
            this.startX = 0;
            setTimeout(() => { this.dragMoved = false; }, 120);
        }
    };
}

window.cardCarousel = cardCarousel;

if (window.Alpine) {
    window.Alpine.data('cardCarousel', cardCarousel);
}

document.addEventListener('alpine:init', () => {
    if (window.Alpine) {
        window.Alpine.data('cardCarousel', cardCarousel);
    }
});

// Copy text to clipboard helper with SweetAlert2 toast
function copyToClipboard(text, successMsg = 'Copied to clipboard!') {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(() => {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: successMsg,
                    showConfirmButton: false,
                    timer: 2000
                });
            } else {
                alert(successMsg);
            }
        });
    } else {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        alert(successMsg);
    }
}

// Client-side confirmation before submitting orders or adding funds
document.addEventListener('DOMContentLoaded', () => {
    const orderForm = document.getElementById('orderForm');
    if (orderForm) {
        orderForm.addEventListener('submit', async (e) => {
            const price = document.getElementById('totalPrice')?.innerText || '';
            const qty = document.getElementById('quantityInput')?.value || '';
            
            if (typeof Swal !== 'undefined') {
                e.preventDefault();
                const confirmed = await window.notify.confirm(
                    `Are you sure you want to place this order for ${Number(qty).toLocaleString()} quantity (${price})?`,
                    'Confirm Order'
                );
                if (confirmed) {
                    orderForm.submit();
                }
            }
        });
    }
});
