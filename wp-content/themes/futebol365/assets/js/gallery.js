document.addEventListener("DOMContentLoaded", function () {
  const mainStage = document.querySelector("#main_carousel .stage"); 
  const slides = document.querySelectorAll("#main_carousel .slide");
  const thumbs = document.querySelectorAll("#thumbs_carousel .slide");
  const pagination = document.querySelectorAll("#pagination_main li");
  const paginationDots = document.querySelectorAll("#pagination_thumbs li");
  const thumbsStage = document.querySelector("#thumbs_carousel .stage");

  if (!slides.length) return;

  let current = 0;
  const thumbsPerPage = 5;

  // Arrow navigation items
  const prevButton = document.querySelector("#pagination_main .previous");
  const nextButton = document.querySelector("#pagination_main .next");

  if (prevButton) {
    prevButton.addEventListener("click", function (e) {
      e.preventDefault();
      if (current > 0) {
        current--;
        showSlide(current);
      }
    });
  }

  if (nextButton) {
    nextButton.addEventListener("click", function (e) {
      e.preventDefault();
      if (current < slides.length - 1) {
        current++;
        showSlide(current);
      }
    });
  }

  /**
   * Translates the thumbnail stage row seamlessly
   */
  function updateThumbPosition(index) {
    const thumbPage = Math.floor(index / thumbsPerPage);
    const offset = thumbPage * 100;
    if (thumbsStage) {
      thumbsStage.style.transform = `translateX(-${offset}%)`;
      thumbsStage.style.transition = "transform 0.3s ease-in-out";
    }
  }

  /**
   * Applies conditional styling state classes to arrow controllers
   */
  function updateNavigation() {
    if (prevButton) prevButton.classList.toggle("disabled", current === 0);
    if (nextButton) nextButton.classList.toggle("disabled", current === slides.length - 1);
  }

  /**
   * Master function coordinating the hardware-accelerated glide movement
   */
 function showSlide(index) {
	current = Math.max(0, Math.min(index, slides.length - 1));

	if (mainStage) {
		const offset = current * 100;
		mainStage.style.transform = `translateX(-${offset}%)`;
		mainStage.style.transition = "transform 0.5s ease-in-out";
	}

	slides.forEach((slide, i) =>
		slide.classList.toggle("active", i === current)
	);

	thumbs.forEach((thumb, i) =>
		thumb.classList.toggle("active", i === current)
	);

	// Main pagination numbers
	pagination.forEach((item, i) => {
		if (
			!item.classList.contains("previous") &&
			!item.classList.contains("next")
		) {
			item.classList.toggle(
				"active",
				(i - 1) === current
			);
		}
	});

	// Thumbnail dotted pagination
	const currentThumbPage = Math.floor(
		current / thumbsPerPage
	);

	paginationDots.forEach((dot, i) => {
		dot.classList.toggle(
			"active",
			i === currentThumbPage
		);
	});

	updateThumbPosition(current);
	updateNavigation();
}

  // Init start layout setup
  showSlide(0);

  // Thumbnail elements interaction bindings
  thumbs.forEach((thumb, index) => {
    thumb.addEventListener("click", function (e) {
      e.preventDefault();
      showSlide(index);
    });
  });

  // Numeric inline menu actions mapping
  pagination.forEach((item, index) => {
    const link = item.querySelector("a");
    if (!link || item.classList.contains("previous") || item.classList.contains("next")) return;

    link.addEventListener("click", function (e) {
      e.preventDefault();
      showSlide(index - 1); // Compensates for leading index items
    });
  });

  // Grouped block chunks navigation clicks
  paginationDots.forEach((dot, index) => {
    const link = dot.querySelector("a");
    if (!link) return;
    link.addEventListener("click", function (e) {
      e.preventDefault();
      showSlide(index * thumbsPerPage);
    });
  });
});
