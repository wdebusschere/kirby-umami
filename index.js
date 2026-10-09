(function () {
  const RANGES = [
    { value: '24h', label: '24 hours', prev: 'previous 24 hours' },
    { value: '7d', label: '7 days', prev: 'previous 7 days' },
    { value: '30d', label: '30 days', prev: 'previous 30 days' },
    { value: '90d', label: '90 days', prev: 'previous 90 days' }
  ];

  // Breakdown cards of the dashboard, in display order: events and goals
  // sit right under the summary, the breakdowns follow. `type` is the Umami
  // metrics type the plugin's /api/plugin/umami/metrics endpoint accepts;
  // `column` heads the first table column and `count` the second (default
  // "Visitors"). `goals` is not a metrics type: loadTable() reads it from
  // /api/plugin/umami/goals and maps every goal onto the {x, y} row shape
  // of the other tables; the card is only shown when the website has goals.
  const CARDS = [
    {
      key: 'events',
      title: 'Events',
      tabs: [
        { type: 'event', label: 'Events', column: 'Event' }
      ]
    },
    {
      key: 'goals',
      title: 'Goals',
      tabs: [
        { type: 'goals', label: 'Goals', column: 'Goal', count: 'Conversions' }
      ]
    },
    {
      key: 'pages',
      title: 'Pages',
      tabs: [
        { type: 'path', label: 'Pages', column: 'Page' },
        { type: 'entry', label: 'Entry', column: 'Entry page' },
        { type: 'exit', label: 'Exit', column: 'Exit page' },
        { type: 'title', label: 'Titles', column: 'Title' }
      ]
    },
    {
      key: 'referrers',
      title: 'Referrers',
      tabs: [
        { type: 'referrer', label: 'Referrers', column: 'Referrer' },
        { type: 'channel', label: 'Channels', column: 'Channel' }
      ]
    },
    {
      key: 'environment',
      title: 'Environment',
      tabs: [
        { type: 'browser', label: 'Browsers', column: 'Browser' },
        { type: 'os', label: 'OS', column: 'Operating system' },
        { type: 'device', label: 'Devices', column: 'Device' },
        { type: 'screen', label: 'Screens', column: 'Screen size' }
      ]
    },
    {
      key: 'location',
      title: 'Location',
      tabs: [
        { type: 'country', label: 'Countries', column: 'Country' },
        { type: 'region', label: 'Regions', column: 'Region' },
        { type: 'city', label: 'Cities', column: 'City' },
        { type: 'language', label: 'Languages', column: 'Language' }
      ]
    }
  ];

  const BROWSERS = {
    chrome: 'Chrome',
    crios: 'Chrome (iOS)',
    'chromium-webview': 'Chromium WebView',
    'edge-chromium': 'Edge',
    edge: 'Edge (Legacy)',
    'edge-ios': 'Edge (iOS)',
    firefox: 'Firefox',
    fxios: 'Firefox (iOS)',
    safari: 'Safari',
    ios: 'Safari (iOS)',
    'ios-webview': 'iOS WebView',
    samsung: 'Samsung Internet',
    opera: 'Opera',
    'opera-mini': 'Opera Mini',
    facebook: 'Facebook',
    instagram: 'Instagram',
    android: 'Android Browser',
    yandexbrowser: 'Yandex',
    silk: 'Silk',
    miui: 'MIUI Browser',
    vivaldi: 'Vivaldi',
    brave: 'Brave',
    ie: 'Internet Explorer',
    curl: 'curl',
    searchbot: 'Search bot',
    bb10: 'BlackBerry'
  };

  const CHANNELS = {
    direct: 'Direct',
    referral: 'Referral',
    organicSearch: 'Organic search',
    paidSearch: 'Paid search',
    organicSocial: 'Organic social',
    paidSocial: 'Paid social',
    organicShopping: 'Organic shopping',
    paidShopping: 'Paid shopping',
    organicVideo: 'Organic video',
    paidVideo: 'Paid video',
    display: 'Display',
    email: 'Email',
    affiliate: 'Affiliate',
    sms: 'SMS',
    audio: 'Audio',
    unknown: 'Unknown'
  };

  const capitalize = (value) => value.charAt(0).toUpperCase() + value.slice(1);
  const camelToWords = (value) => capitalize(value.replace(/([A-Z])/g, ' $1').toLowerCase());

  const displayNames = (type) => {
    try {
      return new Intl.DisplayNames([navigator.language || 'en'], { type });
    } catch (error) {
      return null;
    }
  };

  const regionNames = displayNames('region');
  const languageNames = displayNames('language');

  const countryName = (code) => {
    if (!code || code.length !== 2) {
      return code || 'Unknown';
    }

    try {
      return (regionNames && regionNames.of(code.toUpperCase())) || code;
    } catch (error) {
      return code;
    }
  };

  const flag = (code) => {
    if (!code || !/^[a-z]{2}$/i.test(code)) {
      return '';
    }

    return code
      .toUpperCase()
      .replace(/./g, (char) => String.fromCodePoint(127397 + char.charCodeAt(0)));
  };

  const languageName = (code) => {
    try {
      return (languageNames && languageNames.of(code)) || code;
    } catch (error) {
      return code;
    }
  };

  const formatNumber = (value) => new Intl.NumberFormat().format(value);

  panel.plugin('akibeo/umami', {
    components: {
      'k-umami-view': {
        template: `
          <k-panel-inside class="k-umami-view">
            <k-header>
              Analytics
              <template #buttons>
                <k-button-group>
                  <k-button
                    v-if="shareUrl"
                    icon="open"
                    :link="shareUrl"
                    target="_blank"
                    variant="filled"
                  >
                    Public report
                  </k-button>
                  <k-button
                    icon="chart"
                    :link="dashboardUrl"
                    target="_blank"
                    variant="filled"
                    theme="info"
                  >
                    Umami dashboard
                  </k-button>
                </k-button-group>
              </template>
            </k-header>

            <k-box v-if="!enabled" theme="notice" style="margin-bottom: 1.5rem;">
              <k-text>
                Tracking is off: set <code>akibeo.umami.enabled</code> to <code>true</code> and fill in
                <code>akibeo.umami.websiteId</code> in the site config.
              </k-text>
            </k-box>

            <k-box v-else-if="debug && !trackInDebug" theme="info" style="margin-bottom: 1.5rem;">
              <k-text>
                Kirby's debug mode is on, so the tracker script is not loaded on this
                environment. Set <code>akibeo.umami.trackInDebug</code> to <code>true</code> to test it here.
              </k-text>
            </k-box>

            <template v-if="hasApi">
              <div class="k-umami-toolbar">
                <div class="k-umami-ranges" role="group" aria-label="Period">
                  <button
                    v-for="option in ranges"
                    :key="option.value"
                    type="button"
                    :aria-pressed="range === option.value ? 'true' : 'false'"
                    @click="setRange(option.value)"
                  >
                    {{ option.label }}
                  </button>
                </div>
                <div class="k-umami-toolbar-right">
                  <span v-if="active !== null" class="k-umami-live">
                    <i class="k-umami-live-dot"></i>
                    {{ formatNumber(active) }} {{ active === 1 ? 'visitor' : 'visitors' }} online
                  </span>
                  <k-button icon="refresh" size="sm" variant="filled" :disabled="loading" @click="load(true)">
                    {{ loading ? 'Loading…' : 'Refresh' }}
                  </k-button>
                </div>
              </div>

              <k-box v-if="error" theme="negative" style="margin-bottom: 1.5rem;">
                <k-text>{{ error }}</k-text>
              </k-box>

              <section class="k-umami-card">
                <div class="k-umami-tiles">
                  <div v-for="tile in tiles" :key="tile.key" class="k-umami-tile">
                    <div class="k-umami-tile-label">{{ tile.label }}</div>
                    <div class="k-umami-tile-value">
                      <span v-if="stats" class="k-umami-tile-number">{{ tile.value }}</span>
                      <span v-else class="k-umami-skeleton"></span>
                      <span
                        v-if="tile.delta !== null"
                        class="k-umami-delta"
                        :data-trend="tile.trend"
                        :title="'Compared to the ' + rangeInfo.prev"
                      >{{ tile.delta > 0 ? '+' : '' }}{{ tile.delta }}%</span>
                    </div>
                  </div>
                </div>

                <div class="k-umami-chart">
                  <div class="k-umami-legend">
                    <span data-series="pageviews">Pageviews</span>
                    <span data-series="sessions">Visits</span>
                  </div>
                  <template v-if="chart.buckets.length">
                    <div class="k-umami-bars">
                      <div
                        v-for="line in chart.gridlines"
                        :key="'g' + line.value"
                        class="k-umami-bars-gridline"
                        :style="{ bottom: line.offset + '%' }"
                      ><span>{{ formatNumber(line.value) }}</span></div>
                      <div
                        v-for="bucket in chart.buckets"
                        :key="bucket.key"
                        class="k-umami-bar"
                        :title="bucket.title"
                      >
                        <i data-series="pageviews" :style="{ height: bucket.pageviewsHeight + '%' }"></i>
                        <i data-series="sessions" :style="{ height: bucket.sessionsHeight + '%' }"></i>
                      </div>
                    </div>
                    <div class="k-umami-axis">
                      <span v-for="bucket in chart.buckets" :key="'a' + bucket.key">{{ bucket.axis }}</span>
                    </div>
                  </template>
                  <div v-else class="k-umami-empty">
                    {{ loading ? 'Loading…' : 'No pageviews in this period.' }}
                  </div>
                </div>
              </section>

              <div class="k-umami-grid">
                <section v-for="card in visibleCards" :key="card.key" class="k-umami-card">
                  <div class="k-umami-card-header">
                    <h2 class="k-umami-card-title">{{ card.title }}</h2>
                  </div>
                  <nav class="k-umami-tabs" role="tablist">
                    <button
                      v-for="(tab, index) in card.tabs"
                      :key="tab.type"
                      type="button"
                      role="tab"
                      :aria-selected="card.tab === index ? 'true' : 'false'"
                      @click="selectTab(card, index)"
                    >
                      {{ tab.label }}
                    </button>
                  </nav>

                  <div class="k-umami-table">
                    <div class="k-umami-table-head">
                      <span>{{ card.tabs[card.tab].column }}</span>
                      <span style="text-align: right;">{{ card.tabs[card.tab].count || 'Visitors' }}</span>
                      <span></span>
                    </div>

                    <template v-if="table(card).loading">
                      <div v-for="n in 5" :key="'s' + n" class="k-umami-row k-umami-row-skeleton">
                        <span class="k-umami-skeleton" :style="{ width: (70 - n * 9) + '%' }"></span>
                        <span></span>
                        <span></span>
                      </div>
                    </template>

                    <div v-else-if="table(card).error" class="k-umami-table-error">
                      {{ table(card).error }}
                    </div>

                    <div v-else-if="!table(card).rows.length" class="k-umami-empty">
                      No data for this period.
                    </div>

                    <template v-else>
                      <div
                        v-for="row in visibleRows(card)"
                        :key="row.key"
                        class="k-umami-row"
                      >
                        <span class="k-umami-row-bar" :style="{ width: row.share + '%' }"></span>
                        <span class="k-umami-row-label" :title="row.title">
                          <span v-if="row.flag" class="k-umami-flag">{{ row.flag }}</span>
                          <span>
                            <a v-if="row.href" :href="row.href" target="_blank" rel="noopener">{{ row.label }}</a>
                            <template v-else>{{ row.label }}</template>
                          </span>
                        </span>
                        <span class="k-umami-row-count">{{ formatNumber(row.count) }}</span>
                        <span class="k-umami-row-pct">{{ row.percent }}%</span>
                      </div>

                      <div v-if="table(card).rows.length > pageSize" class="k-umami-table-footer">
                        <button type="button" @click="toggleExpanded(card)">
                          {{ card.expanded ? 'Show less' : 'Show all ' + table(card).rows.length }}
                        </button>
                      </div>
                    </template>
                  </div>
                </section>
              </div>
            </template>

            <k-box v-else-if="enabled" theme="info" style="margin-bottom: 1.5rem;">
              <k-text>
                Add an API key (<code>akibeo.umami.apiKey</code>) or a username and password
                (<code>akibeo.umami.username</code> / <code>password</code>) to the config to see
                visitors and pageviews here.
              </k-text>
            </k-box>

            <div v-if="shareUrl" class="k-umami-report">
              <iframe :src="shareUrl" title="Umami report" loading="lazy"></iframe>
            </div>
          </k-panel-inside>
        `,
        props: {
          enabled: Boolean,
          websiteId: String,
          hostUrl: String,
          dashboardUrl: String,
          shareUrl: String,
          hasApi: Boolean,
          siteUrl: String,
          trackInDebug: Boolean,
          debug: Boolean
        },
        data() {
          let range = '7d';

          try {
            const stored = window.localStorage.getItem('akibeo.umami.range');
            if (RANGES.some((option) => option.value === stored)) {
              range = stored;
            }
          } catch (error) {
            // localStorage unavailable, keep the default
          }

          return {
            loading: false,
            error: null,
            stats: null,
            prev: null,
            active: null,
            series: null,
            range,
            ranges: RANGES,
            pageSize: 10,
            cards: CARDS.map((card) => ({ ...card, tab: 0, expanded: false })),
            // Breakdown tables keyed by "<type>:<range>".
            tables: {}
          };
        },
        computed: {
          rangeInfo() {
            return RANGES.find((option) => option.value === this.range) || RANGES[1];
          },
          goalsCard() {
            return this.cards.find((card) => card.key === 'goals');
          },
          // The goals card is shown once the website turns out to have
          // goals, or when loading them failed. While a new period loads,
          // it stays if an earlier period had goals, so the grid does not
          // jump.
          goalsVisible() {
            const current = this.table(this.goalsCard);

            if (current.error || current.rows.length) {
              return true;
            }

            if (!current.loading) {
              return false;
            }

            return Object.keys(this.tables).some(
              (key) => key.startsWith('goals:') && this.tables[key].rows.length > 0
            );
          },
          visibleCards() {
            return this.cards.filter((card) => card.key !== 'goals' || this.goalsVisible);
          },
          tiles() {
            const stats = this.stats || {};

            return [
              { key: 'visitors', label: 'Visitors', value: formatNumber(stats.visitors || 0), ...this.delta('visitors') },
              { key: 'visits', label: 'Visits', value: formatNumber(stats.visits || 0), ...this.delta('visits') },
              { key: 'pageviews', label: 'Pageviews', value: formatNumber(stats.pageviews || 0), ...this.delta('pageviews') },
              { key: 'bounceRate', label: 'Bounce rate', value: (stats.bounceRate || 0) + '%', ...this.delta('bounceRate', true) },
              { key: 'avgTime', label: 'Avg. visit time', value: stats.avgTime || '0s', ...this.delta('avgSeconds') }
            ];
          },
          chart() {
            const buckets = (this.series && this.series.buckets) || [];
            const max = Math.max(1, ...buckets.map((bucket) => bucket.pageviews));
            const hourly = this.series && this.series.unit === 'hour';
            const every = hourly ? 3 : buckets.length > 60 ? 15 : buckets.length > 14 ? 5 : 1;

            // Round the axis maximum to a "nice" number for the gridlines.
            const magnitude = Math.pow(10, Math.floor(Math.log10(max)));
            const nice = Math.ceil(max / magnitude) * magnitude;
            const steps = nice / magnitude <= 2 ? 4 : nice / magnitude <= 5 ? 5 : nice / magnitude;
            const gridlines = [];
            for (let i = 1; i <= steps; i++) {
              const value = Math.round((nice / steps) * i);
              gridlines.push({ value, offset: (value / nice) * 100 });
            }

            return {
              gridlines,
              buckets: buckets.map((bucket, index) => ({
                key: bucket.key,
                axis: index % every === 0 ? bucket.label : '',
                pageviewsHeight: (bucket.pageviews / nice) * 100,
                sessionsHeight: (bucket.sessions / nice) * 100,
                title: bucket.label + ': ' + formatNumber(bucket.pageviews) + ' pageviews, ' + formatNumber(bucket.sessions) + ' visits'
              }))
            };
          }
        },
        mounted() {
          if (this.hasApi) {
            this.load();
          }
        },
        methods: {
          formatNumber,
          delta(key, lowerIsBetter = false) {
            if (!this.stats || !this.prev || !this.prev[key]) {
              return { delta: null, trend: null };
            }

            const delta = Math.round(((this.stats[key] - this.prev[key]) / this.prev[key]) * 100);
            let trend = delta > 0 ? 'up' : delta < 0 ? 'down' : null;

            if (lowerIsBetter && trend) {
              trend = trend === 'up' ? 'down' : 'up';
            }

            return { delta, trend };
          },
          setRange(range) {
            if (range === this.range) {
              return;
            }

            this.range = range;

            try {
              window.localStorage.setItem('akibeo.umami.range', range);
            } catch (error) {
              // ignore
            }

            this.load();
          },
          tableKey(type) {
            return type + ':' + this.range;
          },
          table(card) {
            const key = this.tableKey(card.tabs[card.tab].type);

            return this.tables[key] || { loading: true, error: null, rows: [], total: 0 };
          },
          selectTab(card, index) {
            card.tab = index;
            card.expanded = false;
            this.loadTable(card.tabs[index].type);
          },
          toggleExpanded(card) {
            card.expanded = !card.expanded;
          },
          visibleRows(card) {
            const tab = card.tabs[card.tab];
            const data = this.table(card);
            const rows = card.expanded ? data.rows : data.rows.slice(0, this.pageSize);
            const max = Math.max(1, ...data.rows.map((row) => row.y));
            // Umami shows each row as a share of all visitors of the period;
            // fall back to the table's own total while the summary loads.
            const denominator = Math.max(1, (this.stats && this.stats.visitors) || data.total);

            return rows.map((row, index) => {
              const formatted = this.formatRow(tab.type, row);
              // A goal's share is its conversion rate, as Umami shows it.
              const percent = tab.type === 'goals'
                ? row.rate
                : Math.min(100, Math.round((row.y / denominator) * 100));

              return {
                key: index + ':' + row.x,
                count: row.y,
                share: tab.type === 'goals' ? percent : (row.y / max) * 100,
                percent,
                ...formatted
              };
            });
          },
          formatRow(type, row) {
            const value = row.x;

            switch (type) {
              case 'path':
              case 'entry':
              case 'exit':
                return { label: value, title: value, href: this.pageUrl(value), flag: '' };
              case 'referrer':
                return { label: value, title: value, href: /^[\w.-]+\.[a-z]{2,}$/i.test(value) ? 'https://' + value : null, flag: '' };
              case 'channel':
                return { label: CHANNELS[value] || camelToWords(value), title: value, href: null, flag: '' };
              case 'browser':
                return { label: BROWSERS[value] || capitalize(value), title: value, href: null, flag: '' };
              case 'device':
                return { label: capitalize(value), title: value, href: null, flag: '' };
              case 'language':
                return { label: languageName(value), title: value, href: null, flag: '' };
              case 'country':
                return { label: countryName(value), title: value, href: null, flag: flag(value) };
              case 'region': {
                const country = row.country || value.split('-')[0];
                const code = value.includes('-') ? value.split('-').slice(1).join('-') : value;
                return { label: code + ', ' + countryName(country), title: value, href: null, flag: flag(country) };
              }
              case 'city':
                return {
                  label: row.country ? value + ', ' + countryName(row.country) : value,
                  title: value,
                  href: null,
                  flag: flag(row.country)
                };
              case 'goals': {
                const page = row.type === 'path' || row.type === 'url';
                const target = (page ? 'Page ' : 'Event ') + row.value;

                return {
                  label: value,
                  title: row.description ? target + ' (' + row.description + ')' : target,
                  href: page && !row.value.includes('*') ? this.pageUrl(row.value) : null,
                  flag: ''
                };
              }
              default:
                return { label: value, title: value, href: null, flag: '' };
            }
          },
          pageUrl(path) {
            if (!this.siteUrl || !path.startsWith('/')) {
              return null;
            }

            try {
              return new URL(this.siteUrl).origin + path;
            } catch (error) {
              return null;
            }
          },
          async load(force = false) {
            if (force) {
              this.tables = {};
            }

            this.loading = true;
            this.error = null;

            const overview = this.loadOverview();
            const tables = this.cards.map((card) => this.loadTable(card.tabs[card.tab].type));

            await Promise.all([overview, ...tables]);

            this.loading = false;
          },
          async loadOverview() {
            try {
              const response = await this.$api.get('plugin/umami/stats', { range: this.range });

              if (response.status !== 'success') {
                throw new Error(response.message || 'Could not load stats');
              }

              if (response.range !== this.range) {
                return; // the period changed while loading
              }

              this.stats = response.stats;
              this.prev = response.prev;
              this.series = response.series;
              this.active = typeof response.active === 'number' ? response.active : null;
            } catch (error) {
              this.stats = null;
              this.prev = null;
              this.series = null;
              this.active = null;
              this.error = error.message || 'Could not load stats';
            }
          },
          async loadTable(type) {
            const key = this.tableKey(type);

            if (this.tables[key] && !this.tables[key].loading) {
              return;
            }

            this.$set(this.tables, key, { loading: true, error: null, rows: [], total: 0 });

            try {
              const response = type === 'goals'
                ? await this.$api.get('plugin/umami/goals', { range: this.range })
                : await this.$api.get('plugin/umami/metrics', { type, range: this.range, limit: 50 });

              if (response.status !== 'success') {
                throw new Error(response.message || 'Could not load ' + type);
              }

              const rows = type === 'goals'
                ? response.goals.map((goal) => ({
                    x: goal.name,
                    y: goal.conversions,
                    rate: goal.rate,
                    visitors: goal.visitors,
                    type: goal.type,
                    value: goal.value,
                    description: goal.description
                  }))
                : response.rows;
              const total = type === 'goals' ? rows.length : response.total;

              this.$set(this.tables, key, { loading: false, error: null, rows, total });
            } catch (error) {
              this.$set(this.tables, key, { loading: false, error: error.message || 'Could not load ' + type, rows: [], total: 0 });
            }
          }
        }
      }
    }
  });
})();
