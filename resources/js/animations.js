import 'lenis/dist/lenis.css';
import Lenis from 'lenis';
import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import { animate, inView, hover, stagger } from 'motion';

gsap.registerPlugin(ScrollTrigger);

const prefersReducedMotion = typeof window !== 'undefined'
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

let lenisInstance = null;

/**
 * Split text nodes into animated spans without corrupting inner HTML tags or RDFa attributes.
 */
const splitWordsIntoSpans = (element) => {
    if (element.dataset.wordsSplit === 'true') {
        return Array.from(element.querySelectorAll('.word-inner'));
    }

    element.dataset.wordsSplit = 'true';

    const processNode = (node) => {
        if (node.nodeType === Node.TEXT_NODE) {
            const text = node.textContent ?? '';
            if (! text.trim()) {
                return [node];
            }

            const parts = text.split(/(\s+)/);
            const fragment = document.createDocumentFragment();

            parts.forEach((part) => {
                if (! part) {
                    return;
                }

                if (/^\s+$/.test(part)) {
                    fragment.appendChild(document.createTextNode(part));
                } else {
                    const outer = document.createElement('span');
                    outer.className = 'word-wrap';
                    outer.style.display = 'inline-block';
                    outer.style.overflow = 'hidden';
                    outer.style.verticalAlign = 'top';

                    const inner = document.createElement('span');
                    inner.className = 'word-inner';
                    inner.style.display = 'inline-block';
                    inner.textContent = part;

                    outer.appendChild(inner);
                    fragment.appendChild(outer);
                }
            });

            return [fragment];
        }

        if (node.nodeType === Node.ELEMENT_NODE) {
            const children = Array.from(node.childNodes);
            children.forEach((child) => {
                const replacements = processNode(child);
                if (replacements && replacements[0] !== child) {
                    node.replaceChild(replacements[0], child);
                }
            });

            return [node];
        }

        return [node];
    };

    const topChildren = Array.from(element.childNodes);
    topChildren.forEach((child) => {
        const replacements = processNode(child);
        if (replacements && replacements[0] !== child) {
            element.replaceChild(replacements[0], child);
        }
    });

    return Array.from(element.querySelectorAll('.word-inner'));
};

/**
 * Initialize GSAP reveal animations: word-by-word titles and vertical reveal-up blocks.
 */
export const initGsapTextAndScrollReveals = (root = document) => {
    if (prefersReducedMotion) {
        return;
    }

    // 1. Reveal titles per word
    const titleElements = root.querySelectorAll('[data-reveal-words]');
    titleElements.forEach((heading) => {
        if (heading.dataset.revealedWords === 'true') {
            return;
        }

        const words = splitWordsIntoSpans(heading);
        if (words.length === 0) {
            return;
        }

        heading.dataset.revealedWords = 'true';

        gsap.fromTo(
            words,
            { y: 24, opacity: 0 },
            {
                y: 0,
                opacity: 1,
                stagger: 0.045,
                duration: 0.65,
                ease: 'power3.out',
                scrollTrigger: {
                    trigger: heading,
                    start: 'top 92%',
                    once: true,
                },
            },
        );
    });

    // 2. Reveal groups with stagger
    const staggerGroups = root.querySelectorAll('[data-reveal-stagger]');
    staggerGroups.forEach((group) => {
        if (group.dataset.staggerInit === 'true') {
            return;
        }

        const items = Array.from(group.querySelectorAll('[data-reveal-up]')).filter(
            (item) => item.dataset.revealed !== 'true',
        );

        if (items.length === 0) {
            return;
        }

        group.dataset.staggerInit = 'true';
        items.forEach((item) => {
            item.dataset.revealed = 'true';
        });

        gsap.fromTo(
            items,
            { y: 28, opacity: 0 },
            {
                y: 0,
                opacity: 1,
                stagger: 0.08,
                duration: 0.65,
                ease: 'power3.out',
                scrollTrigger: {
                    trigger: group,
                    start: 'top 90%',
                    once: true,
                },
            },
        );
    });

    // 3. Standalone reveal-up elements
    const standaloneItems = Array.from(root.querySelectorAll('[data-reveal-up]')).filter(
        (item) => item.dataset.revealed !== 'true' && ! item.closest('[data-reveal-stagger]'),
    );

    standaloneItems.forEach((element) => {
        element.dataset.revealed = 'true';

        gsap.fromTo(
            element,
            { y: 28, opacity: 0 },
            {
                y: 0,
                opacity: 1,
                duration: 0.65,
                ease: 'power3.out',
                scrollTrigger: {
                    trigger: element,
                    start: 'top 92%',
                    once: true,
                },
            },
        );
    });
};

/**
 * Initialize Motion animations for cards: staggered entrance on inView + smooth lift and zoom on hover.
 */
export const initMotionCards = (root = document) => {
    if (prefersReducedMotion) {
        return;
    }

    // 1. Grid-based cards entrance
    const grids = root.querySelectorAll('.grid, [data-motion-grid]');
    grids.forEach((grid) => {
        const cards = Array.from(grid.querySelectorAll('.destination-card, [data-motion-card]')).filter(
            (c) => c.dataset.motionEntered !== 'true',
        );

        if (cards.length === 0) {
            return;
        }

        cards.forEach((card) => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
        });

        inView(
            grid,
            () => {
                animate(
                    cards,
                    { opacity: [0, 1], y: [20, 0] },
                    { delay: stagger(0.06), duration: 0.45, ease: 'easeOut' },
                );
                cards.forEach((card) => {
                    card.dataset.motionEntered = 'true';
                });
            },
            { amount: 0.1 },
        );
    });

    // Standalone cards not inside a .grid
    const standaloneCards = Array.from(root.querySelectorAll('.destination-card, [data-motion-card]')).filter(
        (c) => ! c.closest('.grid') && c.dataset.motionEntered !== 'true',
    );

    standaloneCards.forEach((card) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        inView(
            card,
            () => {
                animate(
                    card,
                    { opacity: [0, 1], y: [20, 0] },
                    { duration: 0.45, ease: 'easeOut' },
                );
                card.dataset.motionEntered = 'true';
            },
            { amount: 0.1 },
        );
    });

    // 2. Micro-interaction hover
    const allCards = root.querySelectorAll('.destination-card, [data-motion-card]');
    allCards.forEach((card) => {
        if (card.dataset.motionHover === 'true') {
            return;
        }

        card.dataset.motionHover = 'true';
        const img = card.querySelector('img');

        hover(card, (element) => {
            animate(element, { y: -4 }, { duration: 0.25, ease: 'easeOut' });
            if (img) {
                animate(img, { scale: 1.04 }, { duration: 0.25, ease: 'easeOut' });
            }

            return () => {
                animate(element, { y: 0 }, { duration: 0.25, ease: 'easeOut' });
                if (img) {
                    animate(img, { scale: 1.0 }, { duration: 0.25, ease: 'easeOut' });
                }
            };
        });
    });
};

/**
 * Setup Lenis Smooth Scroll synchronized with GSAP ScrollTrigger loop.
 */
export const initLenisSmoothScroll = () => {
    if (prefersReducedMotion) {
        return null;
    }

    // Do not initialize window-level smooth scroll on full-viewport map page
    const isFullMapPage = Boolean(
        document.getElementById('map') && ! document.getElementById('detail-map'),
    );

    if (isFullMapPage) {
        return null;
    }

    if (lenisInstance) {
        return lenisInstance;
    }

    lenisInstance = new Lenis({
        duration: 1.1,
        smoothWheel: true,
    });

    lenisInstance.on('scroll', ScrollTrigger.update);

    gsap.ticker.add((time) => {
        lenisInstance?.raf(time * 1000);
    });

    gsap.ticker.lagSmoothing(0);

    return lenisInstance;
};

/**
 * Re-trigger animations and refresh ScrollTrigger when cards or sections are dynamically loaded via AJAX.
 */
export const refreshWayfinderAnimations = (container = document) => {
    if (prefersReducedMotion) {
        return;
    }

    initGsapTextAndScrollReveals(container);
    initMotionCards(container);
    ScrollTrigger.refresh();
};

export const initWayfinderAnimations = () => {
    initLenisSmoothScroll();
    initGsapTextAndScrollReveals();
    initMotionCards();
};
