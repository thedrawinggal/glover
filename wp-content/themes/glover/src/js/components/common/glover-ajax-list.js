// JS Logic for list that loads its contents with AJAX calls.
//
// See https://docs.google.com/document/d/1qQzwOHLRsMRzdC3ZvTDTfPBs8raXK-8fPp_CP0SHcLo for more info on this list
// approach. Using this approach requires this file and _glover-ajax-list.scss.

const showingClass = 'showing';
const disabledClass = 'disabled';
const loadMorePageUrlParam = 'load-more-page-number';

const setUpLists = () => {
    // for each list, set up event handlers and call reloadList() on it
    document.querySelectorAll('.glover-ajax-list-wrapper').forEach(function (listElement) {
        // back/forward history change handling
        addEventListener("popstate", (event) => {
            removeLoadMorePageUrlParam();
            reloadList(listElement);
        });

        // filter option setup
        listElement.querySelectorAll('.glover-ajax-list-filter').forEach(function (filterOption) {
            const optionParamName = filterOption.getAttribute('data-parameter-name');
            const optionParamValue = filterOption.getAttribute('data-parameter-value');

            // set selected status based on current URL
            let currentUrl = new URL(window.location);
            if (currentUrl.searchParams.has(optionParamName, optionParamValue)) {
                filterOption.classList.add('selected');
            }

            filterOption.addEventListener("click", function (e) {
                let updatedUrl = new URL(window.location);

                if (filterOption.classList.contains('selected')) {
                    filterOption.classList.remove('selected');
                    updatedUrl.searchParams.delete(optionParamName, optionParamValue);
                } else {
                    filterOption.classList.add('selected');
                    updatedUrl.searchParams.append(optionParamName, optionParamValue);
                }

                // reset page
                updatedUrl.searchParams.delete(loadMorePageUrlParam);

                updateListParameters(updatedUrl, listElement);
            });
        });

        // clear all option setup
        listElement.querySelectorAll('.glover-clear-all-filters-option').forEach(function (clearAllOption) {
            clearAllOption.addEventListener("click", function () {
                let urlWithoutFilters = new URL(window.location);
                listElement.querySelectorAll('.glover-ajax-list-filter').forEach(function (filterOption) {
                    const optionParamName = filterOption.getAttribute('data-parameter-name');
                    const optionParamValue = filterOption.getAttribute('data-parameter-value');
                    urlWithoutFilters.searchParams.delete(optionParamName, optionParamValue);
                });

                // reset page
                urlWithoutFilters.searchParams.delete(loadMorePageUrlParam);

                updateListParameters(urlWithoutFilters, listElement);
            });
        });

        // "load more" option setup
        const listUsesLoadMoreFeature = listElement.getAttribute('data-use-load-more');
        if (listUsesLoadMoreFeature) {
            let loadMoreOption = listElement.querySelector('.ajax-list-load-more-option');
            loadMoreOption.addEventListener("click", function (e) {
                e.preventDefault();
                let updatedUrl = new URL(window.location);
                if (updatedUrl.searchParams.has(loadMorePageUrlParam)) {
                    let pageNumberToUse = parseInt(updatedUrl.searchParams.get(loadMorePageUrlParam)) + 1
                    updatedUrl.searchParams.delete(loadMorePageUrlParam);
                    updatedUrl.searchParams.set(loadMorePageUrlParam, '' + pageNumberToUse);
                } else {
                    // if URL has no page number in it, it means we're on page 1, and loading more should load page 2.
                    updatedUrl.searchParams.set(loadMorePageUrlParam, "2");
                }

                updateListParameters(updatedUrl, listElement, true);
            });
        }

        // on initial load, remove loadMorePageUrlParam if present.
        removeLoadMorePageUrlParam();

        refreshFilterDisplay(listElement);

        // Each PHP list page loads its initial set of list results, displays its list HTML, and stores additional
        // details as JSON in a hidden element. Load those details here so that we can process them the same way we do
        // for AJAX list loads. Processing this here instead of in PHP means we don't duplicate this logic in PHP and
        // JS.
        let jsonDetailsElement = document.querySelector('.glover-ajax-list-initial-details-json');
        if (jsonDetailsElement) {
            let jsonString = jsonDetailsElement.innerHTML;
            let initialListDetails = JSON.parse(jsonString);
            processFetchedListDetails(listElement, initialListDetails);
        }
    });
};

// Set whether or not each filter option is selected based on selected filters in URL. Update displayed selected filter
// counts based on those selections.
const refreshFilterDisplay = (listElement) => {
    let currentUrl = new URL(window.location);
    let totalSelectedFiltersCount = 0;

    listElement.querySelectorAll('.glover-filter-group').forEach(function (filterGroup) {
        let groupSelectedFiltersCount = 0;

        filterGroup.querySelectorAll('.glover-ajax-list-filter').forEach(function (filterOption) {
            const optionParamName = filterOption.getAttribute('data-parameter-name');
            const optionParamValue = filterOption.getAttribute('data-parameter-value');

            // set selected status based on current URL
            if (currentUrl.searchParams.has(optionParamName, optionParamValue)) {
                filterOption.classList.add('selected');
                groupSelectedFiltersCount++;
                totalSelectedFiltersCount++;
            } else {
                filterOption.classList.remove('selected');
            }
        });

        filterGroup.querySelectorAll('.glover-label-with-filter-count').forEach(function (labelWithFilterCount) {
            let baseLabel = labelWithFilterCount.getAttribute('data-base-label');
            if (groupSelectedFiltersCount > 0) {
                labelWithFilterCount.innerHTML = baseLabel + ' (' + groupSelectedFiltersCount + ')';
            } else {
                labelWithFilterCount.innerHTML = baseLabel
            }
        });
    });

    listElement.querySelectorAll('.glover-clear-all-filters-option-section').forEach(function (clearAllSection) {
        if (totalSelectedFiltersCount > 0) {
            clearAllSection.classList.add(showingClass)
        } else {
            clearAllSection.classList.remove(showingClass)
        }
    });
}

const removeLoadMorePageUrlParam = () => {
    let urlWithoutLoadMorePageUrlParam = new URL(window.location);
    if (urlWithoutLoadMorePageUrlParam.searchParams.has(loadMorePageUrlParam)) {
        urlWithoutLoadMorePageUrlParam.searchParams.delete(loadMorePageUrlParam);
        window.history.pushState({}, "", urlWithoutLoadMorePageUrlParam);
    }
}

const processFetchedListDetails = (listElement, listDetails) => {
    const listUsesLoadMoreFeature = listElement.getAttribute('data-use-load-more');
    let loadMoreOption = listElement.querySelector('.ajax-list-load-more-option');
    if (listUsesLoadMoreFeature && loadMoreOption) {
        // hide / show "load more" button
        if ('' + listDetails.canLoadMore === "true") {
            loadMoreOption.classList.add(showingClass)
        } else {
            loadMoreOption.classList.remove(showingClass)
        }
    }

    // enable/disable filter options if API returns counts for each filter option
    if (listDetails.resultCountsForFilters) {
        document.querySelectorAll('.glover-list-filter-set').forEach(function (filterSet) {
            let setHasVisibleOptions = false;
            let filterName = filterSet.getAttribute('data-parameter-name');
            let countsForFilterSet = listDetails.resultCountsForFilters[filterName];
            if (countsForFilterSet) {
                document.querySelectorAll(`.glover-ajax-list-filter[data-parameter-name="${filterName}"]`)
                    .forEach(function (filterOption) {
                        let filterOptionValue = filterOption.getAttribute('data-parameter-value');
                        let baseCountForOption = parseInt(countsForFilterSet[filterOptionValue]['base']);
                        if (baseCountForOption || filterOption.classList.contains('selected')) {
                            setHasVisibleOptions = true;
                            filterOption.classList.add(showingClass)
                        } else {
                            filterOption.classList.remove(showingClass)
                        }

                        let furtherFilteredCountForOption =
                            parseInt(countsForFilterSet[filterOptionValue]['withCurrentFilters']);
                        if (furtherFilteredCountForOption || filterOption.classList.contains('selected')) {
                            filterOption.classList.remove(disabledClass)
                        } else {
                            filterOption.classList.add(disabledClass)
                        }
                    });

                if (setHasVisibleOptions) {
                    filterSet.classList.add(showingClass)
                } else {
                    filterSet.classList.remove(showingClass)
                }
            }
        })
    }

    // scroll to top of list if list has fewer elements than the set threshold and if user is scrolled
    // below it OR if user is scrolled past bottom of list after refresh
    const desktopScrollToTopThreshold =
        (parseInt(listElement.getAttribute('data-desktop-scroll-to-top-threshold')) || 0);
    if (
        (
            window.scrollY > listElement.offsetTop &&
            listDetails.resultCount < desktopScrollToTopThreshold
        ) || window.scrollY > (listElement.offsetTop + listElement.clientHeight)
    ) {
        let listWrapper = listElement.closest('.glover-ajax-list-wrapper')
        let listWrapperTopOffset = listWrapper.getBoundingClientRect().top;
        let headerHeight = 0
        let headerSelector = '.' + listWrapper.getAttribute("data-header-wrapper-class");
        let headerElement = document.querySelector(headerSelector);
        if (headerElement) {
            headerHeight = headerElement.clientHeight;
        }
        let offsetPosition = listWrapperTopOffset + window.scrollY - headerHeight;

        window.scrollTo({
            top: offsetPosition,
            behavior: "smooth"
        });
    }
}

const reloadList = (listElement, loadMore) => {
    // show loading indicator
    const loadingIndicatorSelector = '.' + listElement.getAttribute('data-loading-indicator-class');
    const loadingIndicator = document.querySelector(loadingIndicatorSelector);
    loadingIndicator.classList.add(showingClass);

    // load data
    let url = listElement.getAttribute('data-list-fetch-url');
    const apiAction = listElement.getAttribute('data-api-action');
    const excludePostId = listElement.getAttribute('data-exclude-post-id');
    const listUsesLoadMoreFeature = listElement.getAttribute('data-use-load-more');
    const pageSize = listElement.getAttribute('data-page-size');

    const formData = new FormData();
    formData.append('action', apiAction);
    formData.append('exclude-post-id', excludePostId);

    if (pageSize) {
        formData.append('page-size', pageSize);
    }

    let currentUrl = new URL(window.location);

    if (listUsesLoadMoreFeature) {
        let pageNumber = 1;
        if (currentUrl.searchParams.has(loadMorePageUrlParam)) {
            pageNumber = currentUrl.searchParams.get(loadMorePageUrlParam);
        }
        formData.append('page-number', pageNumber);
    }

    // append filter parameters from URL to formData
    currentUrl.searchParams.forEach((value, key) => {
        if (key !== 'loadMorePageUrlParam') {
            formData.append(key + '[]', value);
        }
    })
    listElement.querySelectorAll('.glover-ajax-list-arg').forEach(function (listArg) {
        formData.append(listArg.getAttribute('data-arg-label'), listArg.textContent);
    });

    refreshFilterDisplay(listElement);

    fetch(
        url,
        {
            method: 'POST',
            body: formData
        }
    )
        .then((response) => response.json())
        .then(function (responseData) {
            if (responseData.success) {
                // process returned html
                if (loadMore) {
                    // append list items in returned HTML to displayed list items
                    let virtualDiv = document.createElement('div')
                    virtualDiv.innerHTML = responseData.data.listHtml
                    listElement.querySelector('.glover-ajax-list-content .ajax-list-items').innerHTML =
                        listElement.querySelector('.glover-ajax-list-content .ajax-list-items').innerHTML +
                        virtualDiv.querySelector('.ajax-list-items').innerHTML
                } else {
                    // replace list body with returned HTML
                    listElement.querySelector('.glover-ajax-list-content').innerHTML = responseData.data.listHtml;
                }

                // process additional returned details
                processFetchedListDetails(listElement, responseData.data.details);
            } else {
                console.log('Error fetching list data: ', responseData);
            }

            // hide loading indicator
            loadingIndicator.classList.remove(showingClass);
        })
        .catch(function (error) {
            console.log('Error fetching list data: ', error);
            loadingIndicator.classList.remove(showingClass);
        });
};

const updateListParameters = (urlWithUpdatedParams, listElement, loadMore) => {
    // push history update to browser
    window.history.pushState({}, "", urlWithUpdatedParams);

    // call reloadList, which will reload the list using the updated URL
    reloadList(listElement, loadMore);
};

export {setUpLists};
