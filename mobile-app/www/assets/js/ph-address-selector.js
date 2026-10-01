/**
 * Philippine Standard Geographic Code (PSGC) Cascading Address Selector
 * Palma's Elite Gym Management System
 * 
 * Provides unified, accessible, touch-friendly cascading dropdowns:
 * Region -> Province -> City / Municipality -> Barangay
 * 
 * Features:
 * - Self-hosted PSGC dataset with no third-party API dependency
 * - Instant 0ms rendering for Region III / Nueva Ecija / Talavera with built-in cache
 * - Full nationwide cascading lookup via api/ph_address.php (CORS & Capacitor enabled)
 * - Seamless manual entry fallback ("Other - Type Manually")
 * - Safe pre-selection & legacy preservation for existing member profiles
 * - Clean human name storage for 100% database compatibility
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

  // Built-in Region Dataset (All 17 Administrative Regions)
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

  // Fast offline / 0ms pre-cache for gym primary service territory (Region III & Nueva Ecija)
  const FAST_PROVINCES_R3 = [
    { code: '037700000', name: 'Aurora' },
    { code: '030800000', name: 'Bataan' },
    { code: '031400000', name: 'Bulacan' },
    { code: '034900000', name: 'Nueva Ecija' },
    { code: '035400000', name: 'Pampanga' },
    { code: '036900000', name: 'Tarlac' },
    { code: '037100000', name: 'Zambales' }
  ];

  const FAST_CITIES_NE = [
    { code: '034901000', name: 'Aliaga' },
    { code: '034902000', name: 'Bongabon' },
    { code: '034903000', name: 'Cabanatuan City' },
    { code: '034904000', name: 'Cabiao' },
    { code: '034905000', name: 'Carranglan' },
    { code: '034906000', name: 'Cuyapo' },
    { code: '034907000', name: 'Gabaldon' },
    { code: '034908000', name: 'Gapan City' },
    { code: '034909000', name: 'General Mamerto Natividad' },
    { code: '034910000', name: 'General Tinio' },
    { code: '034911000', name: 'Guimba' },
    { code: '034912000', name: 'Jaen' },
    { code: '034913000', name: 'Laur' },
    { code: '034914000', name: 'Licab' },
    { code: '034915000', name: 'Llanera' },
    { code: '034916000', name: 'Lupao' },
    { code: '034917000', name: 'Nampicuan' },
    { code: '034918000', name: 'Palayan City' },
    { code: '034919000', name: 'Pantabangan' },
    { code: '034920000', name: 'Peñaranda' },
    { code: '034921000', name: 'Quezon' },
    { code: '034922000', name: 'Rizal' },
    { code: '034923000', name: 'San Antonio' },
    { code: '034924000', name: 'San Isidro' },
    { code: '034925000', name: 'San Jose City' },
    { code: '034926000', name: 'San Leonardo' },
    { code: '034927000', name: 'Santa Rosa' },
    { code: '034928000', name: 'Santo Domingo' },
    { code: '034929000', name: 'Science City of Muñoz' },
    { code: '034930000', name: 'Talavera' },
    { code: '034931000', name: 'Talugtug' },
    { code: '034932000', name: 'Zaragoza' }
  ];

  const FAST_BARANGAYS_TALAVERA = [
    { code: '034930001', name: 'Andal Alino' },
    { code: '034930002', name: 'Bagong Sikat' },
    { code: '034930003', name: 'Bagong Silang' },
    { code: '034930004', name: 'Bakal I' },
    { code: '034930005', name: 'Bakal II' },
    { code: '034930006', name: 'Bakal III' },
    { code: '034930007', name: 'Baluga' },
    { code: '034930008', name: 'Bantug' },
    { code: '034930009', name: 'Batingcol' },
    { code: '034930010', name: 'Bugtong na Buli' },
    { code: '034930011', name: 'Bulac' },
    { code: '034930012', name: 'Burnay' },
    { code: '034930013', name: 'Caaniplahan' },
    { code: '034930014', name: 'Cabubulaunan' },
    { code: '034930015', name: 'Calipahan' },
    { code: '034930016', name: 'Campos' },
    { code: '034930017', name: 'Casulucan Este' },
    { code: '034930018', name: 'Collado' },
    { code: '034930019', name: 'Concepcion' },
    { code: '034930020', name: 'Coronel Samson' },
    { code: '034930021', name: 'Dimasalang Norte' },
    { code: '034930022', name: 'Dimasalang Sur' },
    { code: '034930023', name: 'Dinarayat' },
    { code: '034930024', name: 'Esguerra' },
    { code: '034930025', name: 'Gulod' },
    { code: '034930026', name: 'Homestead I' },
    { code: '034930027', name: 'Homestead II' },
    { code: '034930028', name: 'Kinalanguyan' },
    { code: '034930029', name: 'La Torre' },
    { code: '034930030', name: 'Lomboy' },
    { code: '034930031', name: 'Mabuhay' },
    { code: '034930032', name: 'Maestrang Kikay' },
    { code: '034930033', name: 'Mamandil' },
    { code: '034930034', name: 'Marcos District' },
    { code: '034930035', name: 'Matias' },
    { code: '034930036', name: 'Matingkis' },
    { code: '034930037', name: 'Minabuyoc' },
    { code: '034930038', name: 'Pag-asa' },
    { code: '034930039', name: 'Paludpod' },
    { code: '034930040', name: 'Pantoc Bulac' },
    { code: '034930041', name: 'Pinagpanaan' },
    { code: '034930042', name: 'Poblacion' },
    { code: '034930043', name: 'Pula' },
    { code: '034930044', name: 'Pulong San Miguel' },
    { code: '034930045', name: 'Purok Collins' },
    { code: '034930046', name: 'Sampaloc' },
    { code: '034930047', name: 'San Miguel na Munti' },
    { code: '034930048', name: 'San Pascual' },
    { code: '034930049', name: 'San Ricardo' },
    { code: '034930050', name: 'Sibul' },
    { code: '034930051', name: 'Sicsican Matanda' },
    { code: '034930052', name: 'Tabacao' },
    { code: '034930053', name: 'Tagche' },
    { code: '034930054', name: 'Valle' }
  ];

  // In-memory request cache
  const apiCache = {
    provinces: {
      '030000000': FAST_PROVINCES_R3
    },
    cities: {
      '034900000': FAST_CITIES_NE,
      '034900000_030000000': FAST_CITIES_NE,
      '034900000_': FAST_CITIES_NE
    },
    barangays: {
      '034930000': FAST_BARANGAYS_TALAVERA
    }
  };

  function isNumericCode(val) {
    return typeof val === 'string' && /^\d{6,12}$/.test(val.trim());
  }

  function resolveApiUrl(customBase) {
    if (customBase) return customBase.replace(/\/$/, '') + '/ph_address.php';
    if (typeof API_URL !== 'undefined' && API_URL) {
      return API_URL.replace(/\/$/, '') + '/ph_address.php';
    }
    if (typeof window !== 'undefined' && window.location) {
      const host = window.location.hostname;
      const protocol = window.location.protocol;
      const isCapacitor = Boolean(window.Capacitor || protocol === 'capacitor:' || (host === 'localhost' && (!window.location.port || window.location.port === '')));
      if (!isCapacitor) {
        const isLocal = host === 'localhost' || host === '127.0.0.1' || host === '::1';
        if (isLocal) {
          const path = window.location.pathname;
          if (path.includes('/member/')) return '../api/ph_address.php';
          if (path.includes('/mobile-app/')) return '../../api/ph_address.php';
          return 'api/ph_address.php';
        }
      }
    }
    return 'https://palmas-gym-4oxn.onrender.com/api/ph_address.php';
  }

  function fetchJson(url) {
    return fetch(url, { cache: 'force-cache' }).then(function (res) {
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
        if (show && val !== undefined && !isNumericCode(val)) {
          input.value = val;
        } else if (!show) {
          input.value = '';
        }
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
        const isSel = Boolean(preselectedRegion && (preselectedRegion === r.code || preselectedRegion === val || preselectedRegion === r.name || preselectedRegion.toLowerCase() === r.name.toLowerCase()));
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
      }).catch(function (err) {
        console.warn('Network fetch failed, checking fallback cache:', err);
        return apiCache.provinces[cacheKey] || [];
      });

      return fetcher.then(function (provinces) {
        if (!provinces || provinces.length === 0) {
          provinces = apiCache.provinces[cacheKey] || [];
        }

        elProvince.innerHTML = '';
        elProvince.appendChild(createOption('', 'Select Province ▼', !preselectedProv));

        let foundMatch = false;
        provinces.forEach(function (p) {
          const isSel = Boolean(preselectedProv && (
            p.code === preselectedProv || 
            p.name.toLowerCase() === String(preselectedProv).toLowerCase()
          ));
          if (isSel) foundMatch = true;
          const opt = createOption(p.name, p.name, isSel);
          opt.setAttribute('data-code', p.code);
          opt.setAttribute('data-name', p.name);
          elProvince.appendChild(opt);
        });

        // Add Other option
        const shouldSelectOther = Boolean(preselectedProv && !foundMatch && !isNumericCode(preselectedProv));
        elProvince.appendChild(createOption('OTHER', 'Other Province (Type Manually)', shouldSelectOther));
        elProvince.disabled = false;

        if (shouldSelectOther) {
          toggleCustom(elProvCustomWrap, elProvCustom, true, preselectedProv);
        } else {
          toggleCustom(elProvCustomWrap, elProvCustom, false);
        }

        if (foundMatch) {
          const activeOpt = elProvince.querySelector('option:checked');
          const codeToLoad = activeOpt ? (activeOpt.getAttribute('data-code') || activeOpt.value) : provinces[0].code;
          return loadCities(codeToLoad, regionCode, cfg.initialCity || cfg.defaultCity);
        } else if (provinces.length > 0 && !preselectedProv) {
          return Promise.resolve();
        }
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
        toggleCustom(elBrgyCustomWrap, elBrgyCustom, true, cfg.initialBarangay || cfg.defaultBarangay || '');
        return Promise.resolve();
      }

      toggleCustom(elProvCustomWrap, elProvCustom, false);
      resetSelect(elCity, 'Loading Cities/Municipalities...');
      resetSelect(elBarangay, 'Select Barangay ▼');

      const cacheKey = provinceCode + '_' + (regionCode || '');
      const cached = apiCache.cities[cacheKey] || apiCache.cities[provinceCode];

      const url = apiEndpoint + '?action=cities&province_code=' + encodeURIComponent(provinceCode) + (regionCode ? '&region_code=' + encodeURIComponent(regionCode) : '');
      const fetcher = cached ? Promise.resolve(cached) : fetchJson(url).then(function (res) {
        if (res && res.success && Array.isArray(res.cities)) {
          apiCache.cities[cacheKey] = res.cities;
          apiCache.cities[provinceCode] = res.cities;
          return res.cities;
        }
        return [];
      }).catch(function (err) {
        console.warn('Network fetch failed, checking fallback cache for cities:', err);
        return apiCache.cities[cacheKey] || apiCache.cities[provinceCode] || [];
      });

      return fetcher.then(function (cities) {
        if (!cities || cities.length === 0) {
          cities = apiCache.cities[cacheKey] || apiCache.cities[provinceCode] || [];
        }

        elCity.innerHTML = '';
        elCity.appendChild(createOption('', 'Select City / Municipality ▼', !preselectedCity));

        let foundMatch = false;
        cities.forEach(function (c) {
          const isSel = Boolean(preselectedCity && (
            c.code === preselectedCity || 
            c.name.toLowerCase() === String(preselectedCity).toLowerCase() || 
            ('city of ' + c.name.toLowerCase()) === String(preselectedCity).toLowerCase() || 
            c.name.toLowerCase().replace(/^city of\s+/i, '') === String(preselectedCity).toLowerCase()
          ));
          if (isSel) foundMatch = true;
          const opt = createOption(c.name, c.name, isSel);
          opt.setAttribute('data-code', c.code);
          opt.setAttribute('data-name', c.name);
          elCity.appendChild(opt);
        });

        // Add Other option
        const shouldSelectOther = Boolean(preselectedCity && !foundMatch && !isNumericCode(preselectedCity));
        elCity.appendChild(createOption('OTHER', 'Other City / Municipality (Type Manually)', shouldSelectOther));
        elCity.disabled = false;

        if (shouldSelectOther) {
          toggleCustom(elCityCustomWrap, elCityCustom, true, preselectedCity);
        } else {
          toggleCustom(elCityCustomWrap, elCityCustom, false);
        }

        if (foundMatch) {
          const activeOpt = elCity.querySelector('option:checked');
          const codeToLoad = activeOpt ? (activeOpt.getAttribute('data-code') || activeOpt.value) : cities[0].code;
          return loadBarangays(codeToLoad, cfg.initialBarangay || cfg.defaultBarangay);
        } else if (cities.length > 0 && !preselectedCity) {
          return Promise.resolve();
        }
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
      }).catch(function (err) {
        console.warn('Network fetch failed, checking fallback cache for barangays:', err);
        return apiCache.barangays[cacheKey] || [];
      });

      return fetcher.then(function (barangays) {
        if (!barangays || barangays.length === 0) {
          barangays = apiCache.barangays[cacheKey] || [];
        }

        elBarangay.innerHTML = '';
        elBarangay.appendChild(createOption('', 'Select Barangay ▼', !preselectedBrgy));

        let foundMatch = false;
        const cleanPreselected = preselectedBrgy ? String(preselectedBrgy).toLowerCase().replace(/^(brgy\.?|barangay)\s+/i, '').trim() : '';

        barangays.forEach(function (b) {
          const cleanB = b.name.toLowerCase().replace(/^(brgy\.?|barangay)\s+/i, '').replace(/\s*\(pob\.\)/i, '').trim();
          const isSel = Boolean(preselectedBrgy && (
            b.code === preselectedBrgy || 
            b.name.toLowerCase() === String(preselectedBrgy).toLowerCase() || 
            cleanB === cleanPreselected
          ));
          if (isSel) foundMatch = true;
          const opt = createOption(b.name, b.name, isSel);
          opt.setAttribute('data-code', b.code);
          opt.setAttribute('data-name', b.name);
          elBarangay.appendChild(opt);
        });

        // Add Other option
        const shouldSelectOther = Boolean(preselectedBrgy && !foundMatch && !isNumericCode(preselectedBrgy));
        elBarangay.appendChild(createOption('OTHER', 'Other Barangay (Type Manually)', shouldSelectOther));
        elBarangay.disabled = false;

        if (shouldSelectOther) {
          toggleCustom(elBrgyCustomWrap, elBrgyCustom, true, preselectedBrgy);
        } else {
          toggleCustom(elBrgyCustomWrap, elBrgyCustom, false);
        }
      });
    }

    // Bind Event Listeners
    elRegion.addEventListener('change', function () {
      cfg.initialCity = null;
      cfg.initialBarangay = null;
      cfg.defaultCity = null;
      cfg.defaultBarangay = null;
      const opt = this.querySelector('option:checked');
      const code = opt ? opt.getAttribute('data-code') : this.value;
      loadProvinces(code);
      if (typeof cfg.onChange === 'function') cfg.onChange();
    });

    elProvince.addEventListener('change', function () {
      cfg.initialCity = null;
      cfg.initialBarangay = null;
      cfg.defaultCity = null;
      cfg.defaultBarangay = null;
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
      cfg.defaultBarangay = null;
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
