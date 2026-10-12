document.addEventListener("DOMContentLoaded", function () {

    function handleScroll() {
        const middleHeadBar = document.querySelector(".middle-head-bar");
        const stickyLogo = document.querySelector(".sticky-logo");
        const headerHeight = document.querySelector('header').offsetHeight;
        if (window.scrollY > headerHeight) {
            middleHeadBar?.classList.add("navbar-fixed");
            stickyLogo?.classList.add("navbar-home-icon");
        } else {
            middleHeadBar?.classList.remove("navbar-fixed");
            stickyLogo?.classList.remove("navbar-home-icon");
        }
    }

    function handleResize() {
        const middleHeadBar = document.querySelector(".middle-head-bar");
        const stickyLogo = document.querySelector(".sticky-logo");

        middleHeadBar?.classList.remove("navbar-fixed");
        stickyLogo?.classList.remove("navbar-home-icon");
    }

    window.addEventListener("scroll", handleScroll);
    window.addEventListener("resize", handleResize);

});


document.addEventListener("DOMContentLoaded", function () {
    const mobileMenuBtn = document.querySelector(".mobile-menu-icon");
    const topMenu = document.querySelector(".top-menu-ul");
    const menuLinks = document.querySelectorAll(
        ".middle-head-bar .top-nav > ul > li.menu-item-has-children > a"
    );
    function closeAllSubmenus(except = null) {
        menuLinks.forEach(link => {
            const subMenu = link.nextElementSibling;
            if (link !== except) {
                link.classList.remove("active");
                subMenu?.classList.remove("active");
            }
        });
    }
    function resetMenu() {
        topMenu?.classList.remove("active");
        mobileMenuBtn?.classList.remove("active");
        document.documentElement.classList.remove("mobile-menu-open");
        closeAllSubmenus();
    }
    mobileMenuBtn?.addEventListener("click", function (e) {
        e.stopPropagation();
        if (window.innerWidth <= 959) {
            topMenu?.classList.toggle("active");
            this.classList.toggle("active");
            document.documentElement.classList.toggle("mobile-menu-open");
        }
    });
    menuLinks.forEach(link => {
        link.addEventListener("click", function (e) {
            const subMenu = this.nextElementSibling;
            if (subMenu && subMenu.classList.contains("sub-menu")) {
                e.preventDefault();
                const isActive = this.classList.contains("active");
                closeAllSubmenus();
                if (!isActive) {
                    this.classList.add("active");
                    subMenu.classList.add("active");
                }
            }
        });
    });
    document.addEventListener("click", function (e) {
        const insideMenu = e.target.closest(".top-nav");
        const menuButton = e.target.closest(".mobile-menu-icon");
        if (!insideMenu) {
            closeAllSubmenus();
        }
        if (
            window.innerWidth <= 959 &&
            !insideMenu &&
            !menuButton
        ) {
            resetMenu();
        }

    });
    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") {
            resetMenu();
        }
    });
    window.addEventListener("resize", function () {
        if (window.innerWidth > 959) {
            resetMenu();
        }
    });
});


document.addEventListener("DOMContentLoaded", function () {
    const tabButtons = document.querySelectorAll(".top-tab-block .nav-link");
    const tabPanes = document.querySelectorAll(".top-tab-block .tab-pane");

    tabButtons.forEach(button => {
        button.addEventListener("click", function () {

            // Remove active from all buttons
            tabButtons.forEach(btn => btn.classList.remove("active"));
            this.classList.add("active");

            // Remove show active from all panes
            tabPanes.forEach(pane => {
                pane.classList.remove("show", "active");
            });

            // Get target pane
            const target = this.getAttribute("data-bs-target");
            const targetPane = document.querySelector(target);

            if (targetPane) {
                targetPane.classList.add("show", "active");
            }
        });
    });
});

document.addEventListener("DOMContentLoaded", function () {
    const navMobile = document.querySelector(".nav-mobile");
    const trigger = document.querySelector(".nav-trigger span");
    const mobileUl = document.querySelector("nav.nav-main ul");

    if (trigger && mobileUl) {
        trigger.addEventListener("click", function () {
            if (mobileUl.classList.contains("expanded")) {
                mobileUl.classList.remove("expanded");
                mobileUl.style.display = "none";
                trigger.classList.remove("open");
            } else {
                mobileUl.classList.add("expanded");
                mobileUl.style.display = "block";
                trigger.classList.add("open");
            }
        });
    }
});

document.addEventListener("DOMContentLoaded", function () {
    const iframe = document.querySelector(".videoDetail iframe");
    if (iframe) {
        iframe.classList.add("iframeVideoDetail");
    }
});
document.querySelectorAll('.answer-votes').forEach(el => {
    el.textContent = el.textContent.replace(/\s*\([^)]*\)/, '');
});
document.addEventListener('DOMContentLoaded', function () {

    const lazyImages = document.querySelectorAll('.lazyload-item');

    if ('IntersectionObserver' in window) {

        const imageObserver = new IntersectionObserver(function (entries, observer) {

            entries.forEach(function (entry) {

                if (entry.isIntersecting) {

                    const img = entry.target;

                    if (img.dataset.src) {
                        img.src = img.dataset.src;
                    }

                    if (img.dataset.srcset) {
                        img.srcset = img.dataset.srcset;
                    }

                    img.classList.add('loaded');

                    observer.unobserve(img);
                }

            });

        }, {
            rootMargin: '100px 0px'
        });

        lazyImages.forEach(function (img) {
            imageObserver.observe(img);
        });

    } else {

        // Fallback for older browsers
        lazyImages.forEach(function (img) {

            if (img.dataset.src) {
                img.src = img.dataset.src;
            }

            if (img.dataset.srcset) {
                img.srcset = img.dataset.srcset;
            }

            img.classList.add('loaded');
        });
    }

});