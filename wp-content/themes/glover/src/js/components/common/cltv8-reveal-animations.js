// HTML Element Reveal Animations
// - To enable reveal animations for an element (where it slides up and fades into view), give it the "single-reveal"
//   class.
// - To enable staggered reveal animations for a set of elements (where each element slides up and fades into view, with
//   each element animation starting after a 100ms delay from the previous animated element's start), give their parent
//   the "children-reveal" and give each child the "children-reveal-child" class.
//
// This JS is meant to be as small as possible and to have no other JS dependencies. It uses CSS for its animations.
//
// The reveal trigger approach involves adding an element to each revealable element via JS that acts as the
// reveal trigger. IntersectionObserver, which this uses to detect elements that act as reveal triggers, can only detect
// elements in terms of how much (percentage-wise) of that element is visible in the viewport. So you can say "run a
// function when this element is between 0-100% visible". If you say "zero" there, it seems to just always think it's
// visible. This doesn't work well for element reveals; we want these reveal animations to trigger after about 100px of
// the element appears in the viewport. If we say we want it to trigger at a zero threshold, the animation triggers too
// soon. If we say we want 25% of the element to be visible before it triggers, this can fail if the element is so tall
// that it doesn't fit in the viewport. Having an invisible trigger element that's as tall as the revealable element,
// that has a 100px top offset, and that has a 0.0000001% threshold seems to fix both issues. The part where the trigger
// is 100% as tall as the container may be fragile and may cause issues if used at the very bottom of a page.
//
// This is adapted from SAL (https://github.com/mciastek/sal), which uses the MIT license:
// MIT License
//
// Copyright (c) 2017 Mirosław Ciastek
//
// Permission is hereby granted, free of charge, to any person obtaining a copy
// of this software and associated documentation files (the "Software"), to deal
// in the Software without restriction, including without limitation the rights
// to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
// copies of the Software, and to permit persons to whom the Software is
// furnished to do so, subject to the following conditions:
//
// The above copyright notice and this permission notice shall be included in all
// copies or substantial portions of the Software.
//
// THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
// IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
// FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
// AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
// LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
// OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
// SOFTWARE.

const NOT_SUPPORTED_MESSAGE = ''
    + 'Your browser does not support IntersectionObserver!\n'
    + 'Get a polyfill from here:\n'
    + 'https://github.com/w3c/IntersectionObserver/tree/master/polyfill';

/**
 * Default options
 */
let options = {
    root: null,
    rootMargin: '0% 50%',
    threshold: 0.0000001,
    animateClassName: 'sal-animate',
    disabledClassName: 'sal-disabled',
    enterEventName: 'sal:in',
    exitEventName: 'sal:out',
    selector: '[data-sal]',
    once: true,
};

/**
 * Private
 */
let revealableIdPrefix = 'cltv8-revealable-';
let elementsData = [];
let intersectionObserver = null;

/**
 * Sets options.
 * @param {Object} settings
 */
const setOptions = (settings) => {
    if (settings && settings !== options) {
        options = {
            ...options,
            ...settings,
        };
    }
};

/**
 * Launches animation by adding class.
 * @param {HTMLElement} elementToAnimate
 */
const animate = (elementToAnimate) => {
    elementToAnimate.classList.add(options.animateClassName);
    if (elementToAnimate.classList.contains('children-reveal')) {
        // reveal each child with 0.1s delay between each successive animation start
        const notYetAnimatedChildren = [].filter.call(
            elementToAnimate.querySelectorAll('.children-reveal-child'),
            (element) => !isAnimated(element),
        );

        notYetAnimatedChildren.forEach((child, index) => {
            window.setTimeout(() => {
                child.classList.add(options.animateClassName);
            }, 100 * index)
        });
    }
};

/**
 * Checks if element was animated.
 * @param {HTMLElement} element
 */
const isAnimated = (element) => (
    element.classList.contains(options.animateClassName)
);

/**
 * Enables animations by remove class from body.
 */
const enableAnimations = () => {
    document.body.classList.remove(options.disabledClassName);
};

/**
 * Disables animations by add class from body.
 */
const disableAnimations = () => {
    document.body.classList.add(options.disabledClassName);
};

/**
 * Clears observer.
 */
const clearObserver = () => {
    intersectionObserver.disconnect();
    intersectionObserver = null;
};

/**
 * IntersectionObserver callback.
 * @param  {Array<IntersectionObserverEntry>} revealTriggerElements
 * @param  {IntersectionObserver} observer
 */
const onIntersection = (revealTriggerElements, observer) => {
    revealTriggerElements.forEach((revealTriggerElement) => {
        const target = revealTriggerElement.target;
        const triggeredElementAnimationOrder = parseInt(target.getAttribute('data-animation-order'));

        if (revealTriggerElement.intersectionRatio >= options.threshold) {
            // animate all elements above intersected entry that haven't yet been animated yet
            const notYetAnimatedTriggers = [].filter.call(
                document.querySelectorAll('[data-animation-order]'),
                (element) => !isAnimated(element),
            );

            for (let i = 0; i < notYetAnimatedTriggers.length; i++) {
                const revealTrigger = notYetAnimatedTriggers[i];
                const stringElementAnimationOrder = revealTrigger.getAttribute('data-animation-order');
                const elementAnimationOrder = parseInt(stringElementAnimationOrder);
                const revealableLocator = `[data-animation-id="${revealableIdPrefix}${stringElementAnimationOrder}"]`
                const elementToReveal = document.querySelector(revealableLocator);
                animate(elementToReveal);
                observer.unobserve(revealTrigger);
                if (triggeredElementAnimationOrder === elementAnimationOrder) {
                    break; // stop triggering animations
                }
            }
        }
    });
};

/**
 * Returns collection of elements and pushes them to observer.
 *
 * @returns {Array<Node>}
 */
const setUpObservedElements = () => {
    let revealableElements = [].filter.call(
        document.querySelectorAll('.single-reveal, .children-reveal, .slide-left-reveal'),
        (element) => !isAnimated(element),
    );

    let observedElementsData = [];
    revealableElements.forEach(function (revealableElement, index) {
        revealableElement.setAttribute('data-animation-id', revealableIdPrefix + index);

        let revealTriggerElement = document.createElement('div');
        revealTriggerElement.classList.add("revealable-element-activation-trigger");
        revealTriggerElement.setAttribute('data-animation-order', index);
        revealableElement.prepend(revealTriggerElement);

        observedElementsData.push({
            revealableElement: revealableElement,
            revealTriggerElement: revealTriggerElement
        })
    });

    return observedElementsData;
};

/**
 * Disables instance by removing animations and clearing observer.
 */
const disable = () => {
    disableAnimations();
    clearObserver();
};

/**
 * Enables instance by launching new IntersectionObserver.
 */
const enable = () => {
    enableAnimations();

    intersectionObserver = new IntersectionObserver(onIntersection, {
        root: options.root,
        rootMargin: options.rootMargin,
        threshold: options.threshold,
    });

    elementsData = setUpObservedElements();
    elementsData.forEach((elementData) => {
        intersectionObserver.observe(elementData.revealTriggerElement);
    });
};

/**
 * Init
 * @param  {Object} settings
 * @return {Object} public API
 */
const handleElementReveals = (settings = options) => {
    setOptions(settings);

    if (!window.IntersectionObserver) {
        disableAnimations();
        throw Error(NOT_SUPPORTED_MESSAGE);
    }

    enable();

    return {
        elementsData,
        disable,
        enable,
    };
};

export default handleElementReveals;
