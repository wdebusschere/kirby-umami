panel.plugin('akibeo/umami', {
  components: {
    'k-umami-view': {
      template: `
        <k-panel-inside>
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
            <k-bar style="margin-bottom: 1rem;">
              <k-button-group slot="left">
                <k-button
                  v-for="option in ranges"
                  :key="option.value"
                  :variant="range === option.value ? 'filled' : null"
                  :theme="range === option.value ? 'info' : null"
                  size="sm"
                  @click="setRange(option.value)"
                >
                  {{ option.label }}
                </k-button>
              </k-button-group>
              <k-button slot="right" icon="refresh" size="sm" :disabled="loading" @click="load()">
                {{ loading ? 'Loading…' : 'Refresh' }}
              </k-button>
            </k-bar>

            <k-box v-if="error" theme="negative" style="margin-bottom: 1.5rem;">
              <k-text>{{ error }}</k-text>
            </k-box>

            <div v-if="stats" class="k-umami-stats" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
              <k-box v-for="tile in tiles" :key="tile.key" style="display: block;">
                <k-text>
                  <p style="margin: 0; color: var(--color-text-dimmed); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: .05em;">{{ tile.label }}</p>
                  <p style="margin: .25rem 0 0; font-size: var(--text-2xl); font-weight: var(--font-semi);">{{ tile.value }}</p>
                  <p v-if="tile.delta !== null" style="margin: .25rem 0 0; font-size: var(--text-xs);" :style="{ color: tile.delta >= 0 ? 'var(--color-green-700)' : 'var(--color-red-700)' }">
                    {{ tile.delta >= 0 ? '▲' : '▼' }} {{ Math.abs(tile.delta) }}% vs. previous {{ rangeLabel }}
                  </p>
                </k-text>
              </k-box>
            </div>
          </template>

          <k-box v-else-if="enabled" theme="info" style="margin-bottom: 1.5rem;">
            <k-text>
              Add an API key (<code>akibeo.umami.apiKey</code>) or a username and password
              (<code>akibeo.umami.username</code> / <code>password</code>) to the config to see
              visitors and pageviews here.
            </k-text>
          </k-box>

          <div v-if="shareUrl" style="border: 1px solid var(--color-border); border-radius: var(--rounded); overflow: hidden; background: var(--color-white);">
            <iframe
              :src="shareUrl"
              title="Umami report"
              style="display: block; width: 100%; height: 75vh; border: 0;"
              loading="lazy"
            ></iframe>
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
        trackInDebug: Boolean,
        debug: Boolean
      },
      data() {
        return {
          loading: false,
          error: null,
          stats: null,
          prev: null,
          range: '7d',
          ranges: [
            { value: '24h', label: 'Last 24 hours' },
            { value: '7d', label: 'Last 7 days' },
            { value: '30d', label: 'Last 30 days' },
            { value: '90d', label: 'Last 90 days' }
          ]
        };
      },
      computed: {
        rangeLabel() {
          const map = { '24h': '24 hours', '7d': '7 days', '30d': '30 days', '90d': '90 days' };
          return map[this.range] || this.range;
        },
        tiles() {
          if (!this.stats) {
            return [];
          }

          const number = (value) => new Intl.NumberFormat().format(value);

          return [
            { key: 'visitors', label: 'Visitors', value: number(this.stats.visitors), delta: this.delta('visitors') },
            { key: 'visits', label: 'Visits', value: number(this.stats.visits), delta: this.delta('visits') },
            { key: 'pageviews', label: 'Pageviews', value: number(this.stats.pageviews), delta: this.delta('pageviews') },
            { key: 'bounceRate', label: 'Bounce rate', value: this.stats.bounceRate + '%', delta: this.delta('bounceRate') },
            { key: 'avgTime', label: 'Avg. visit time', value: this.stats.avgTime, delta: null }
          ];
        }
      },
      mounted() {
        if (this.hasApi) {
          this.load();
        }
      },
      methods: {
        delta(key) {
          if (!this.prev || !this.prev[key]) {
            return null;
          }

          return Math.round((this.stats[key] - this.prev[key]) / this.prev[key] * 100);
        },
        setRange(range) {
          this.range = range;
          this.load();
        },
        async load() {
          this.loading = true;
          this.error = null;

          try {
            const response = await this.$api.get('plugin/umami/stats', { range: this.range });

            if (response.status !== 'success') {
              this.stats = null;
              this.prev = null;
              this.error = response.message || 'Could not load stats';
              return;
            }

            this.stats = response.stats;
            this.prev = response.prev;
          } catch (error) {
            this.stats = null;
            this.prev = null;
            this.error = error.message || 'Could not load stats';
          } finally {
            this.loading = false;
          }
        }
      }
    }
  }
});
