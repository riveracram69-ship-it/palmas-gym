/**
 * Philippine Standard Geographic Code (PSGC) Cascading Address Selector
 * Palma's Elite Gym Management System
 * 
 * Provides unified, accessible, touch-friendly cascading dropdowns:
 * Region -> Province -> City / Municipality -> Barangay
 * 
 * Features:
 * - Self-hosted PSGC dataset with no third-party API dependency
 * - Manual-entry fallback when the address service is unavailable
 * - In-memory pre-cache for Region III / Nueva Ecija (0ms instant render)
 * - Seamless "Other (Type Manually)" fallback
 * - Safe pre-selection & legacy preservation for existing member profiles
 * - Value sent is the clean geographical Name (for 100% DB compatibility)
 * - PSGC codes stored in data-code attribute for relational cascading
 * - Clean mobile & web touch targets (min 44px)
 */

(function (root, factory) {
  if (typeof define === 'function' && define.amd) {
    define([], factory);
  } else if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else {
    root.PhilippineAddressSelector = factory();
  }
}(typeof self !== 'undefined' ? self : this, function () {

  // Local fast cache for immediate 0ms rendering of gym's primary service area
  const FAST_REGIONS = [
    { code: '010000000', name: 'Ilocos Region', region_name: 'Region I' },
    { code: '020000000', name: 'Cagayan Valley', region_name: 'Region II' },
    { code: '030000000', name: 'Central Luzon', region_name: 'Region III' },
    { code: '040000000', name: 'CALABARZON', region_name: 'Region IV-A' },
    { code: '050000000', name: 'Bicol Region', region_name: 'Region V' },
    { code: '060000000', name: 'Western Visayas', region_name: 'Region VI' },
    { code: '070000000', name: 'Central Visayas', region_name: 'Region VII' },
    { code: '080000000', name: 'Eastern Visayas', region_name: 'Region VIII' },
    { code: '090000000', name: 'Zamboanga Peninsula', region_name: 'Region IX' },
    { code: '100000000', name: 'Northern Mindanao', region_name: 'Region X' },
    { code: '110000000', name: 'Davao Region', region_name: 'Region XI' },
    { code: '120000000', name: 'SOCCSKSARGEN', region_name: 'Region XII' },
    { code: '130000000', name: 'NCR', region_name: 'National Capital Region' },
    { code: '140000000', name: 'CAR', region_name: 'Cordillera Administrative Region' },
    { code: '150000000', name: 'BARMM', region_name: 'Bangsamoro Autonomous Region' },
    { code: '160000000', name: 'Caraga', region_name: 'Region XIII' },
    { code: '170000000', name: 'MIMAROPA Region', region_name: 'MIMAROPA Region' }
  ];

  // In-memory request cache to avoid redundant API queries
  const apiCache = {
    provinces: {},
    cities: {},
    barangays: {}
  };

  function resolveApiUrl(customBase) {
    if (typeof window !== 'undefined' && window.location) {
      const host = window.location.hostname;
      const isLocal = host === 'localhost' || host === '127.0.0.1' || host === '::1' || window.location.protocol === 'file:';
      if (isLocal) {
        const path = window.location.pathname;
        if (path.includes('/member/')) return '../api/ph_address.php';
        if (path.includes('/mobile-app/')) return '../../api/ph_address.php';
        return 'api/ph_address.php';
      }
    }
    if (customBase) return customBase.replace(/\/$/, '') + '/ph_address.php';
    if (typeof API_URL !== 'undefined' && API_URL) {
      return API_URL.replace(/\/$/, '') + '/ph_address.php';
    }
    const path = (typeof window !== 'undefined' && window.location) ? window.location.pathname : '';
    if (path.includes('/member/')) {
      return '../api/ph_address.php';
    }
    if (path.includes('/mobile-app/')) {
      return '../../api/ph_address.php';
    }
    return 'api/ph_address.php';
  }

  function fetchJson(url) {
    return fetch(url).then(function (res) {
      if (!res.ok) throw new Error('HTTP ' + res.status);
      return res.json();
    });
  }

  function createOption(value, text, selected) {
    const opt = document.createElement('option');
    opt.value = value;
    opt.textContent = text;
    if (selected) opt.selected = true;
    return opt;
  }

  function setupSelector(cfg) {
    const apiEndpoint = resolveApiUrl(cfg.apiBaseUrl);

    const elRegion   = typeof cfg.region === 'string' ? document.querySelector(cfg.region) : cfg.region;
    const elProvince = typeof cfg.province === 'string' ? document.querySelector(cfg.province) : cfg.province;
    const elCity     = typeof cfg.city === 'string' ? document.querySelector(cfg.city) : cfg.city;
    const elBarangay = typeof cfg.barangay === 'string' ? document.querySelector(cfg.barangay) : cfg.barangay;

    // Custom fallback text inputs
    const elProvCustomWrap  = cfg.provinceCustomWrap ? (typeof cfg.provinceCustomWrap === 'string' ? document.querySelector(cfg.provinceCustomWrap) : cfg.provinceCustomWrap) : null;
    const elProvCustom      = cfg.provinceCustom ? (typeof cfg.provinceCustom === 'string' ? document.querySelector(cfg.provinceCustom) : cfg.provinceCustom) : null;
    const elCityCustomWrap  = cfg.cityCustomWrap ? (typeof cfg.cityCustomWrap === 'string' ? document.querySelector(cfg.cityCustomWrap) : cfg.cityCustomWrap) : null;
    const elCityCustom      = cfg.cityCustom ? (typeof cfg.cityCustom === 'string' ? document.querySelector(cfg.cityCustom) : cfg.cityCustom) : null;
    const elBrgyCustomWrap  = cfg.barangayCustomWrap ? (typeof cfg.barangayCustomWrap === 'string' ? document.querySelector(cfg.barangayCustomWrap) : cfg.barangayCustomWrap) : null;
    const elBrgyCustom      = cfg.barangayCustom ? (typeof cfg.barangayCustom === 'string' ? document.querySelector(cfg.barangayCustom) : cfg.barangayCustom) : null;

    if (!elRegion || !elProvince || !elCity || !elBarangay) {
      console.warn('PhilippineAddressSelector: One or more target select elements were not found.', cfg);
      return null;
    }

    // Helper: Reset dropdown
    function resetSelect(selectEl, placeholderText) {
      selectEl.innerHTML = '';
      selectEl.appendChild(createOption('', placeholderText || 'Select Option', true));
      selectEl.disabled = true;
    }

    // Helper: Toggle custom input visibility
    function toggleCustom(wrap, input, show, val) {
      if (wrap) wrap.style.display = show ? 'block' : 'none';
      if (input) {
        if (show && val !== undefined) input.value = val;
        if (!show) input.value = '';
        input.required = show;
      }
    }

    // 1. Populate Regions
    function loadRegions(preselectedRegion) {
      elRegion.innerHTML = '';
      elRegion.appendChild(createOption('', 'Select Region ▼', !preselectedRegion));

      FAST_REGIONS.forEach(function (r) {
        const label = r.region_name ? (r.region_name + ' (' + r.name + ')') : r.name;
        const val = r.region_name || r.name;
        const isSel = Boolean(preselectedRegion && (preselectedRegion === r.code || preselectedRegion === val || preselectedRegion === r.name));
        const opt = createOption(val, label, isSel);
        opt.setAttribute('data-code', r.code);
        opt.setAttribute('data-name', r.name);
        elRegion.appendChild(opt);
      });

      elRegion.disabled = false;
    }

    // 2. Load Provinces for Region
    function loadProvinces(regionCode, preselectedProv) {
      if (!regionCode) {
        resetSelect(elProvince, 'Select Province ▼');
        resetSelect(elCity, 'Select City / Municipality ▼');
        resetSelect(elBarangay, 'Select Barangay ▼');
        toggleCustom(elProvCustomWrap, elProvCustom, false);
        toggleCustom(elCityCustomWrap, elCityCustom, false);
        toggleCustom(elBrgyCustomWrap, elBrgyCustom, false);
        return Promise.resolve();
      }

      resetSelect(elProvince, 'Loading Provinces...');
      resetSelect(elCity, 'Select City / Municipality ▼');
      resetSelect(elBarangay, 'Select Barangay ▼');

      const cacheKey = regionCode;
      const cached = apiCache.provinces[cacheKey];

      const fetcher = cached ? Promise.resolve(cached) : fetchJson(apiEndpoint + '?action=provinces&region_code=' + encodeURIComponent(regionCode)).then(function (res) {
        if (res && res.success && Array.isArray(res.provinces)) {
          apiCache.provinces[cacheKey] = res.provinces;
          return res.provinces;
        }
        return [];
      });

      return fetcher.then(function (provinces) {
        elProvince.innerHTML = '';
        elProvince.appendChild(createOption('', 'Select Province ▼', !preselectedProv));

        let foundMatch = false;
        provinces.forEach(function (p) {
          const isSel = Boolean(preselectedProv && (p.code === preselectedProv || p.name.toLowerCase() === preselectedProv.toLowerCase()));
          if (isSel) foundMatch = true;
          const opt = createOption(p.name, p.name, isSel);
          opt.setAttribute('data-code', p.code);
          opt.setAttribute('data-name', p.name);
          elProvince.appendChild(opt);
        });

        // Add Other option
        elProvince.appendChild(createOption('OTHER', 'Other Province (Type Manually)', Boolean(preselectedProv && !foundMatch)));
        elProvince.disabled = false;

        if (preselectedProv && !foundMatch) {
          toggleCustom(elProvCustomWrap, elProvCustom, true, preselectedProv);
        } else {
          toggleCustom(elProvCustomWrap, elProvCustom, false);
        }

        if (foundMatch) {
          const activeOpt = elProvince.querySelector('option:checked');
          const codeToLoad = activeOpt ? (activeOpt.getAttribute('data-code') || activeOpt.value) : provinces[0].code;
          return loadCities(codeToLoad, regionCode, cfg.initialCity);
        }
      }).catch(function (err) {
        console.warn('Failed to load provinces:', err);
        elProvince.innerHTML = '';
        elProvince.appendChild(createOption('OTHER', 'Other Province (Type Manually)', true));
        elProvince.disabled = false;
        toggleCustom(elProvCustomWrap, elProvCustom, true, preselectedProv || '');
      });
    }

    // 3. Load Cities for Province / Region
    function loadCities(provinceCode, regionCode, preselectedCity) {
      if (!provinceCode) {
        resetSelect(elCity, 'Select City / Municipality ▼');
        resetSelect(elBarangay, 'Select Barangay ▼');
        toggleCustom(elCityCustomWrap, elCityCustom, false);
        toggleCustom(elBrgyCustomWrap, elBrgyCustom, false);
        return Promise.resolve();
      }

      if (provinceCode === 'OTHER') {
        toggleCustom(elProvCustomWrap, elProvCustom, true);
        elCity.innerHTML = '';
        elCity.appendChild(createOption('OTHER', 'Other City (Type Manually)', true));
        elCity.disabled = false;
        toggleCustom(elCityCustomWrap, elCityCustom, true, preselectedCity || '');
        elBarangay.innerHTML = '';
        elBarangay.appendChild(createOption('OTHER', 'Other Barangay (Type Manually)', true));
        elBarangay.disabled = false;
        toggleCustom(elBrgyCustomWrap, elBrgyCustom, true, cfg.initialBarangay || '');
        return Promise.resolve();
      }

      toggleCustom(elProvCustomWrap, elProvCustom, false);
      resetSelect(elCity, 'Loading Cities/Municipalities...');
      resetSelect(elBarangay, 'Select Barangay ▼');

      const cacheKey = provinceCode + '_' + (regionCode || '');
      const cached = apiCache.cities[cacheKey];

      const url = apiEndpoint + '?action=cities&province_code=' + encodeURIComponent(provinceCode) + (regionCode ? '&region_code=' + encodeURIComponent(regionCode) : '');
      const fetcher = cached ? Promise.resolve(cached) : fetchJson(url).then(function (res) {
        if (res && res.success && Array.isArray(res.cities)) {
          apiCache.cities[cacheKey] = res.cities;
          return res.cities;
        }
        return [];
      });

      return fetcher.then(function (cities) {
        elCity.innerHTML = '';
        elCity.appendChild(createOption('', 'Select City / Municipality ▼', !preselectedCity));

        let foundMatch = false;
        cities.forEach(function (c) {
          const isSel = Boolean(preselectedCity && (
            c.code === preselectedCity || 
            c.name.toLowerCase() === preselectedCity.toLowerCase() || 
            ('city of ' + c.name.toLowerCase()) === preselectedCity.toLowerCase() || 
            c.name.toLowerCase().replace(/^city of\s+/i, '') === preselectedCity.toLowerCase()
          ));
          if (isSel) foundMatch = true;
          const opt = createOption(c.name, c.name, isSel);
          opt.setAttribute('data-code', c.code);
          opt.setAttribute('data-name', c.name);
          elCity.appendChild(opt);
        });

        // Add Other option
        elCity.appendChild(createOption('OTHER', 'Other City / Municipality (Type Manually)', Boolean(preselectedCity && !foundMatch)));
        elCity.disabled = false;

        if (preselectedCity && !foundMatch) {
          toggleCustom(elCityCustomWrap, elCityCustom, true, preselectedCity);
        } else {
          toggleCustom(elCityCustomWrap, elCityCustom, false);
        }

        if (foundMatch) {
          const activeOpt = elCity.querySelector('option:checked');
          const codeToLoad = activeOpt ? (activeOpt.getAttribute('data-code') || activeOpt.value) : cities[0].code;
          return loadBarangays(codeToLoad, cfg.initialBarangay);
        }
      }).catch(function (err) {
        console.warn('Failed to load cities:', err);
        elCity.innerHTML = '';
        elCity.appendChild(createOption('OTHER', 'Other City (Type Manually)', true));
        elCity.disabled = false;
        toggleCustom(elCityCustomWrap, elCityCustom, true, preselectedCity || '');
      });
    }

    // 4. Load Barangays for City
    function loadBarangays(cityCode, preselectedBrgy) {
      if (!cityCode) {
        resetSelect(elBarangay, 'Select Barangay ▼');
        toggleCustom(elBrgyCustomWrap, elBrgyCustom, false);
        return Promise.resolve();
      }

      if (cityCode === 'OTHER') {
        toggleCustom(elCityCustomWrap, elCityCustom, true);
        elBarangay.innerHTML = '';
        elBarangay.appendChild(createOption('OTHER', 'Other Barangay (Type Manually)', true));
        elBarangay.disabled = false;
        toggleCustom(elBrgyCustomWrap, elBrgyCustom, true, preselectedBrgy || '');
        return Promise.resolve();
      }

      toggleCustom(elCityCustomWrap, elCityCustom, false);
      resetSelect(elBarangay, 'Loading Barangays...');

      const cacheKey = cityCode;
      const cached = apiCache.barangays[cacheKey];

      const fetcher = cached ? Promise.resolve(cached) : fetchJson(apiEndpoint + '?action=barangays&city_code=' + encodeURIComponent(cityCode)).then(function (res) {
        if (res && res.success && Array.isArray(res.barangays)) {
          apiCache.barangays[cacheKey] = res.barangays;
          return res.barangays;
        }
        return [];
      });

      return fetcher.then(function (barangays) {
        elBarangay.innerHTML = '';
        elBarangay.appendChild(createOption('', 'Select Barangay ▼', !preselectedBrgy));

        let foundMatch = false;
        const cleanPreselected = preselectedBrgy ? preselectedBrgy.toLowerCase().replace(/^(brgy\.?|barangay)\s+/i, '').trim() : '';

        barangays.forEach(function (b) {
          const cleanB = b.name.toLowerCase().replace(/^(brgy\.?|barangay)\s+/i, '').replace(/\s*\(pob\.\)/i, '').trim();
          const isSel = Boolean(preselectedBrgy && (b.code === preselectedBrgy || b.name.toLowerCase() === preselectedBrgy.toLowerCase() || cleanB === cleanPreselected));
          if (isSel) foundMatch = true;
          const opt = createOption(b.name, b.name, isSel);
          opt.setAttribute('data-code', b.code);
          opt.setAttribute('data-name', b.name);
          elBarangay.appendChild(opt);
        });

        // Add Other option
        elBarangay.appendChild(createOption('OTHER', 'Other Barangay (Type Manually)', Boolean(preselectedBrgy && !foundMatch)));
        elBarangay.disabled = false;

        if (preselectedBrgy && !foundMatch) {
          toggleCustom(elBrgyCustomWrap, elBrgyCustom, true, preselectedBrgy);
        } else {
          toggleCustom(elBrgyCustomWrap, elBrgyCustom, false);
        }
      }).catch(function (err) {
        console.warn('Failed to load barangays:', err);
        elBarangay.innerHTML = '';
        elBarangay.appendChild(createOption('OTHER', 'Other Barangay (Type Manually)', true));
        elBarangay.disabled = false;
        toggleCustom(elBrgyCustomWrap, elBrgyCustom, true, preselectedBrgy || '');
      });
    }

    // Bind Event Listeners
    elRegion.addEventListener('change', function () {
      cfg.initialCity = null;
      cfg.initialBarangay = null;
      const opt = this.querySelector('option:checked');
      const code = opt ? opt.getAttribute('data-code') : this.value;
      loadProvinces(code);
      if (typeof cfg.onChange === 'function') cfg.onChange();
    });

    elProvince.addEventListener('change', function () {
      cfg.initialCity = null;
      cfg.initialBarangay = null;
      if (this.value === 'OTHER') {
        toggleCustom(elProvCustomWrap, elProvCustom, true);
        elCity.innerHTML = '';
        elCity.appendChild(createOption('OTHER', 'Other City (Type Manually)', true));
        elCity.disabled = false;
        toggleCustom(elCityCustomWrap, elCityCustom, true);
        elBarangay.innerHTML = '';
        elBarangay.appendChild(createOption('OTHER', 'Other Barangay (Type Manually)', true));
        elBarangay.disabled = false;
        toggleCustom(elBrgyCustomWrap, elBrgyCustom, true);
      } else {
        toggleCustom(elProvCustomWrap, elProvCustom, false);
        const opt = this.querySelector('option:checked');
        const code = opt ? opt.getAttribute('data-code') : this.value;
        const regOpt = elRegion.querySelector('option:checked');
        const regCode = regOpt ? regOpt.getAttribute('data-code') : elRegion.value;
        loadCities(code, regCode);
      }
      if (typeof cfg.onChange === 'function') cfg.onChange();
    });

    elCity.addEventListener('change', function () {
      cfg.initialBarangay = null;
      if (this.value === 'OTHER') {
        toggleCustom(elCityCustomWrap, elCityCustom, true);
        elBarangay.innerHTML = '';
        elBarangay.appendChild(createOption('OTHER', 'Other Barangay (Type Manually)', true));
        elBarangay.disabled = false;
        toggleCustom(elBrgyCustomWrap, elBrgyCustom, true);
      } else {
        toggleCustom(elCityCustomWrap, elCityCustom, false);
        const opt = this.querySelector('option:checked');
        const code = opt ? opt.getAttribute('data-code') : this.value;
        loadBarangays(code);
      }
      if (typeof cfg.onChange === 'function') cfg.onChange();
    });

    elBarangay.addEventListener('change', function () {
      if (this.value === 'OTHER') {
        toggleCustom(elBrgyCustomWrap, elBrgyCustom, true);
      } else {
        toggleCustom(elBrgyCustomWrap, elBrgyCustom, false);
      }
      if (typeof cfg.onChange === 'function') cfg.onChange();
    });

    // Handle initialization & pre-selection
    if (cfg.initialProvince && cfg.initialCity && !cfg.initialRegion) {
      // Reverse lookup to find region
      fetchJson(apiEndpoint + '?action=reverse&province=' + encodeURIComponent(cfg.initialProvince) + '&city=' + encodeURIComponent(cfg.initialCity) + '&barangay=' + encodeURIComponent(cfg.initialBarangay || ''))
        .then(function (res) {
          if (res && res.success && res.match) {
            loadRegions(res.match.region_code);
            return loadProvinces(res.match.region_code, res.match.province_name).then(function () {
              const provOpt = elProvince.querySelector('option:checked');
              const pCode = provOpt ? provOpt.getAttribute('data-code') : res.match.province_code;
              return loadCities(pCode, res.match.region_code, res.match.city_name);
            }).then(function () {
              const cityOpt = elCity.querySelector('option:checked');
              const cCode = cityOpt ? cityOpt.getAttribute('data-code') : res.match.city_code;
              return loadBarangays(cCode, res.match.barangay_name);
            });
          } else {
            // Default fallback
            loadRegions('030000000'); // Central Luzon
            loadProvinces('030000000', cfg.initialProvince);
          }
        })
        .catch(function () {
          loadRegions('030000000');
          loadProvinces('030000000', cfg.initialProvince);
        });
    } else if (cfg.defaultRegion) {
      loadRegions(cfg.defaultRegion);
      loadProvinces(cfg.defaultRegion, cfg.defaultProvince).then(function () {
        if (cfg.defaultProvince) {
          const provOpt = elProvince.querySelector('option:checked');
          const pCode = provOpt ? provOpt.getAttribute('data-code') : cfg.defaultProvince;
          return loadCities(pCode, cfg.defaultRegion, cfg.defaultCity);
        }
      }).then(function () {
        if (cfg.defaultCity) {
          const cityOpt = elCity.querySelector('option:checked');
          const cCode = cityOpt ? cityOpt.getAttribute('data-code') : cfg.defaultCity;
          return loadBarangays(cCode, cfg.defaultBarangay);
        }
      });
    } else {
      // Clean blank initial state
      loadRegions('');
      resetSelect(elProvince, 'Select Province ▼');
      resetSelect(elCity, 'Select City / Municipality ▼');
      resetSelect(elBarangay, 'Select Barangay ▼');
    }

    // Public controller methods
    return {
      getValues: function () {
        const regOpt = elRegion.querySelector('option:checked');
        const provOpt = elProvince.querySelector('option:checked');
        const cityOpt = elCity.querySelector('option:checked');
        const brgyOpt = elBarangay.querySelector('option:checked');

        const provVal = elProvince.value === 'OTHER' ? (elProvCustom ? elProvCustom.value.trim() : '') : (provOpt ? provOpt.value : '');
        const cityVal = elCity.value === 'OTHER' ? (elCityCustom ? elCityCustom.value.trim() : '') : (cityOpt ? cityOpt.value : '');
        const brgyVal = elBarangay.value === 'OTHER' ? (elBrgyCustom ? elBrgyCustom.value.trim() : '') : (brgyOpt ? brgyOpt.value : '');

        return {
          regionCode: regOpt ? (regOpt.getAttribute('data-code') || '') : '',
          regionName: regOpt ? regOpt.value : '',
          provinceCode: provOpt ? (provOpt.getAttribute('data-code') || '') : '',
          province: provVal,
          cityCode: cityOpt ? (cityOpt.getAttribute('data-code') || '') : '',
          municipality: cityVal,
          barangayCode: brgyOpt ? (brgyOpt.getAttribute('data-code') || '') : '',
          barangay: brgyVal
        };
      },
      isValid: function () {
        const vals = this.getValues();
        return Boolean(vals.regionName && vals.province && vals.municipality && vals.barangay);
      }
    };
  }

  return {
    init: setupSelector
  };
}));
