document.addEventListener('DOMContentLoaded', function () {
  
  if (typeof ialChartData === 'undefined') {
    return;
  }

  const ctx = document.getElementById('ialSerialChart');
  if (!ctx) {
    return;
  }

  // Detect dynamic color for Legend only (to match theme)
  const bodyStyles = getComputedStyle(document.body);
  const dynamicTextColor = bodyStyles.color || '#3c434a';

  // Custom Plugin to draw percentage on chart segments
  const percentageLabelPlugin = {
    id: 'percentageLabel',
    afterDatasetsDraw(chart, args, options) {
      const { ctx, data } = chart;
      const dataset = data.datasets[0];

      ctx.save();
      ctx.font = 'bold 12px sans-serif';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';

      dataset.data.forEach((datapoint, i) => {
        // Calculate percentage
        const total = dataset.data.reduce((acc, curr) => acc + curr, 0);
        const value = dataset.data[i];
        
        // Don't draw if value is 0
        if (value === 0) return;
        
        const meta = chart.getDatasetMeta(0);
        if (meta.data[i].hidden) return;

        const percentage = ((value / total) * 100).toFixed(1) + '%';
        const arc = meta.data[i];
        const center = arc.getCenterPoint();
        
        ctx.strokeStyle = '#ffffff'; 
        ctx.lineWidth = 4;
        ctx.strokeText(percentage, center.x, center.y);
        
        ctx.fillStyle = '#000000'; 
        ctx.fillText(percentage, center.x, center.y);
      });
      ctx.restore();
    }
  };

  const ialPieChart = new Chart(ctx.getContext('2d'), {
    type: 'pie',
    plugins: [percentageLabelPlugin],
    data: {
      labels: [
        ialChartData.text_activated,
        ialChartData.text_not_active
      ],
      datasets: [
        {
          data: [
            ialChartData.activated,
            ialChartData.non_activated
          ],
          backgroundColor: [
            '#2271b1', 
            '#d63638'  
          ],
          borderWidth: 1,
          borderColor: '#ffffff',
          hoverOffset: 0,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: 'right',
          align: 'center',
          onClick: null,
          labels: {
            color: dynamicTextColor,
            usePointStyle: true,
            boxWidth: 8,
            padding: 20,
            font: {
                size: 13
            },
            generateLabels: function(chart) {
                const data = chart.data;
                if (data.labels.length && data.datasets.length) {
                    return data.labels.map((label, i) => {
                        const meta = chart.getDatasetMeta(0);
                        const style = meta.controller.getStyle(i);

                        return {
                            text: '  ' + label,
                            fillStyle: style.backgroundColor,
                            strokeStyle: style.borderColor,
                            lineWidth: style.borderWidth,
                            hidden: false,
                            index: i,
                            fontColor: dynamicTextColor
                        };
                    });
                }
                return [];
            }
          }
        },
        title: {
          display: false,
        },
        tooltip: {
            callbacks: {
                label: function(context) {
                    let label = context.label || '';
                    if (label) {
                        label += ': ';
                    }
                    if (context.parsed !== null) {
                        let percentage = (context.parsed / ialChartData.total * 100).toFixed(1);
                        label += context.raw + ' (' + percentage + '%)';
                    }
                    return label;
                }
            }
        }
      },
      layout: {
          padding: 10
      }
    },
  });
});