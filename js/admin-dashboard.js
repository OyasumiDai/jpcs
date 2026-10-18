(() => {
  'use strict';
  const source = document.getElementById('dash-data');
  if (!source) return;
  const data = JSON.parse(source.textContent);
  const ns = 'http://www.w3.org/2000/svg';
  const svgNode = (tag, attributes = {}, text = '') => {
    const node = document.createElementNS(ns, tag);
    Object.entries(attributes).forEach(([key, value]) => node.setAttribute(key, String(value)));
    if (text) node.textContent = text;
    return node;
  };
  const colors = ['var(--chart-1)', 'var(--chart-2)', 'var(--chart-3)', 'var(--chart-4)'];
  document.querySelectorAll('[data-dash-chart]').forEach(container => {
    const key = container.dataset.dashChart;
    let rows = data.charts[key] || [];
    if (!data.available || !rows.length) {
      const message = document.createElement('p');
      message.className = 'dash-empty';
      message.textContent = data.available ? 'No records in this period.' : 'Data unavailable.';
      container.append(message);
      return;
    }
    if (key === 'daily') {
      const values = new Map(rows.map(row => [row.label, Number(row.value)]));
      rows = [];
      for (let day = new Date(data.start + 'T00:00:00Z'); day < new Date(data.end + 'T00:00:00Z'); day.setUTCDate(day.getUTCDate() + 1)) {
        const label = day.toISOString().slice(0, 10);
        rows.push({label, value: values.get(label) || 0});
      }
    }
    const daily = key === 'daily';
    const height = daily ? 220 : Math.max(130, rows.length * 34 + 15);
    const svg = svgNode('svg', {viewBox: `0 0 600 ${height}`, role: 'img', 'aria-label': `${key} chart. Exact values are in the data table below.`});
    const maximum = Math.max(1, ...rows.map(row => Number(row.value)));
    const tooltip = document.createElement('div');
    tooltip.className = 'dash-tooltip'; tooltip.setAttribute('role', 'status');
    const show = row => { tooltip.textContent = `${row.label}: ${row.value}`; };
    rows.forEach((row, index) => {
      const value = Number(row.value);
      const color = daily || key === 'attendance' || key === 'sections' ? colors[0] : colors[index % colors.length];
      const x = daily ? 32 + index * 550 / rows.length : 225;
      const y = daily ? 185 - value / maximum * 155 : index * 34 + 8;
      const width = daily ? Math.max(1, 550 / rows.length - 1) : value / maximum * 310;
      const bar = svgNode('rect', {x, y, width, height: daily ? Math.max(1, value / maximum * 155) : 20, rx: 2, fill: color, tabindex: 0, 'aria-label': `${row.label}: ${value}`});
      bar.append(svgNode('title', {}, `${row.label}: ${value}`));
      bar.addEventListener('pointerenter', () => show(row));
      bar.addEventListener('focus', () => show(row));
      svg.append(bar);
      if (!daily) {
        svg.append(svgNode('text', {x: 0, y: y + 15, class: 'dash-axis'}, row.label.length > 29 ? row.label.slice(0, 28) + '…' : row.label));
        svg.append(svgNode('text', {x: 545, y: y + 15, class: 'dash-axis'}, String(value)));
      }
    });
    if (daily) {
      svg.append(svgNode('text', {x: 0, y: 32, class: 'dash-axis'}, String(maximum)));
      svg.append(svgNode('text', {x: 0, y: 188, class: 'dash-axis'}, '0'));
      svg.append(svgNode('text', {x: 32, y: 210, class: 'dash-axis'}, data.start));
      svg.append(svgNode('text', {x: 580, y: 210, 'text-anchor': 'end', class: 'dash-axis'}, rows[rows.length - 1].label));
    }
    const legend = document.createElement('p'); legend.className = 'dash-legend';
    legend.textContent = key === 'status' ? rows.map(row => `${row.label}: ${row.value}`).join(' · ') : 'Bars: ' + (key === 'attendance' ? 'check-ins' : 'registrations');
    tooltip.textContent = 'Hover or focus a bar for its exact value.';
    container.append(svg, tooltip, legend);
  });
  document.querySelector('[data-dash-print]')?.addEventListener('click', () => window.print());
  const period = document.getElementById('dash-period');
  const updateDates = () => {
    document.querySelectorAll('.dash-filters input[type="date"]').forEach(input => { input.disabled = period.value !== 'custom'; });
  };
  period?.addEventListener('change', updateDates); updateDates();
  document.querySelectorAll('[data-dash-review]').forEach(form => form.addEventListener('submit', event => {
    const removing = event.submitter?.value === 'remove';
    if (!window.confirm(removing ? 'Reject and permanently remove this pending registration?' : 'Approve this registration and send the confirmation email?')) event.preventDefault();
  }));
  document.querySelector('[data-dash-delete-image]')?.addEventListener('submit', event => {
    if (!window.confirm('Permanently remove this gallery image?')) event.preventDefault();
  });
})();
