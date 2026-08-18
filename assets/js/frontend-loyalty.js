/**
 * Loyalty panel intro: the progress bar fills up to the customer's current
 * tier, each milestone lights up as the bar reaches it, and a short confetti
 * burst fires from the tier they are on.
 *
 * Progressive enhancement: PHP renders the final width inline, so with no JS
 * (or with reduced motion requested) the bar is already correct and nothing
 * here is needed.
 */
(function () {
    'use strict';

    var FILL_MS = 1100;
    var CONFETTI_MS = 1800;
    var COLORS = ['#2e7d47', '#f4c542', '#e2574c', '#4a90d9', '#8e6bbf'];

    var panel = document.querySelector('.ial-loyalty-panel');
    if (!panel) {
        return;
    }

    var fill = panel.querySelector('.ial-loyalty-fill');
    if (!fill) {
        return;
    }

    var target = parseFloat(fill.getAttribute('data-fill'));
    if (isNaN(target)) {
        return;
    }

    var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reducedMotion) {
        return;
    }

    var steps = panel.querySelector('.ial-loyalty-steps');
    var reached = panel.querySelectorAll('.ial-loyalty-step.is-reached');

    // Wind everything back before the first paint the visitor sees.
    panel.classList.add('is-animating');
    fill.style.transition = 'none';
    fill.style.width = '0%';
    void fill.offsetWidth;
    fill.style.transition = 'width ' + FILL_MS + 'ms cubic-bezier(.22,.61,.36,1)';

    /** When the bar sweeps past a given milestone, in milliseconds. */
    function delayFor(step) {
        if (!steps || !steps.offsetWidth || target <= 0) {
            return 0;
        }
        var edge = ((step.offsetLeft + step.offsetWidth) / steps.offsetWidth) * 100;
        return Math.min(1, edge / target) * FILL_MS;
    }

    function play() {
        fill.style.width = target + '%';

        Array.prototype.forEach.call(reached, function (step) {
            window.setTimeout(function () {
                step.classList.add('is-popped');
            }, delayFor(step));
        });

        // Hand the final state back to the stylesheet once the intro is over.
        window.setTimeout(function () {
            panel.classList.remove('is-animating');
        }, FILL_MS + 250);

        if (panel.getAttribute('data-celebrate') === '1') {
            window.setTimeout(celebrate, FILL_MS);
        }
    }

    /** Centre of the last milestone reached, relative to the panel. */
    function burstOrigin() {
        var panelRect = panel.getBoundingClientRect();

        if (reached.length) {
            var dot = reached[reached.length - 1].querySelector('.ial-loyalty-dot');
            if (dot) {
                var dotRect = dot.getBoundingClientRect();
                return {
                    x: dotRect.left + dotRect.width / 2 - panelRect.left,
                    y: dotRect.top + dotRect.height / 2 - panelRect.top
                };
            }
        }

        return { x: panelRect.width / 2, y: panelRect.height / 2 };
    }

    function celebrate() {
        var rect = panel.getBoundingClientRect();
        if (!rect.width || !rect.height) {
            return;
        }

        var canvas = document.createElement('canvas');
        canvas.className = 'ial-confetti';
        canvas.setAttribute('aria-hidden', 'true');
        panel.appendChild(canvas);

        var context = canvas.getContext('2d');
        if (!context) {
            panel.removeChild(canvas);
            return;
        }

        var ratio = window.devicePixelRatio || 1;
        canvas.width = rect.width * ratio;
        canvas.height = rect.height * ratio;
        context.scale(ratio, ratio);

        var origin = burstOrigin();
        var pieces = [];

        for (var i = 0; i < 90; i++) {
            // Fan out upwards, roughly a 110 degree spread.
            var angle = (-Math.PI / 2) + (Math.random() - 0.5) * 1.9;
            var speed = 4 + Math.random() * 6;

            pieces.push({
                x: origin.x,
                y: origin.y,
                vx: Math.cos(angle) * speed,
                vy: Math.sin(angle) * speed,
                width: 5 + Math.random() * 5,
                height: 3 + Math.random() * 4,
                rotation: Math.random() * Math.PI,
                spin: (Math.random() - 0.5) * 0.3,
                color: COLORS[i % COLORS.length]
            });
        }

        var startedAt = null;

        function frame(now) {
            if (startedAt === null) {
                startedAt = now;
            }
            var elapsed = now - startedAt;

            context.clearRect(0, 0, rect.width, rect.height);

            pieces.forEach(function (piece) {
                piece.vy += 0.22;
                piece.vx *= 0.99;
                piece.x += piece.vx;
                piece.y += piece.vy;
                piece.rotation += piece.spin;

                context.save();
                context.translate(piece.x, piece.y);
                context.rotate(piece.rotation);
                context.globalAlpha = Math.max(0, 1 - (elapsed / CONFETTI_MS));
                context.fillStyle = piece.color;
                context.fillRect(-piece.width / 2, -piece.height / 2, piece.width, piece.height);
                context.restore();
            });

            if (elapsed < CONFETTI_MS) {
                window.requestAnimationFrame(frame);
            } else if (canvas.parentNode) {
                canvas.parentNode.removeChild(canvas);
            }
        }

        window.requestAnimationFrame(frame);
    }

    // Wait until the panel is actually on screen, so nobody misses the intro
    // because it played above the fold while they were further down.
    if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    observer.disconnect();
                    window.setTimeout(play, 250);
                }
            });
        }, { threshold: 0.35 });
        observer.observe(panel);
    } else {
        window.setTimeout(play, 350);
    }
})();
