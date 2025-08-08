

(function($) {
    /* "use strict" */
	
 var dzChartlist = function(){
	
	var screenWidth = $(window).width();
	let draw = Chart.controllers.line.__super__.draw; //draw shadow
	var activityz = function(){
		var optionsArea = {
          series: [{
            name: "",
            data: [20, 25, 20, 50, 30, 30, 20,40,40]
          },
		  {
            name: "",
            data: [25, 30, 40, 20, 35, 30, 30, 25,30]
          }
        ],
          chart: {
          height: 300,
          type: 'area',
		  group: 'social',
		  toolbar: {
            show: false
          },
          zoom: {
            enabled: false
          },
        },
        dataLabels: {
          enabled: false
        },
        stroke: {
          width: [0, 0],
		  colors:['var(--primary)','#FF6A59'],
		  curve: 'straight'
        },
        legend: {
			show:false,
          tooltipHoverFormatter: function(val, opts) {
            return val + ' - ' + opts.w.globals.series[opts.seriesIndex][opts.dataPointIndex] + ''
          },
        },
    
        xaxis: {
        categories: ['Mon', 'Tus', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun','Mon','Tue'],
		  labels: {
		   style: {
			  colors: '#3E4954',
			  fontSize: '14px',
			   fontFamily: 'Poppins',
			  fontWeight: 100,
			  
			},
		  },
		  axisBorder:{
			  show: false
		  },
		  axisTicks: {
			  
			show:false,  
		  },
        },
		yaxis: {
			labels: {
			offsetX:-16,
			minWidth:40,
			style: {
			  colors: '#3E4954',
			  fontSize: '14px',
			   fontFamily: 'Poppins',
			  fontWeight: 100,
			  
			},
		  },
		   axisTicks: {
			  show: false,
			  borderType: 'solid',
			  color: '#78909C',
			  width: 6,
			  offsetX: 0,
			  offsetY: 0
			},
		},
		
		colors:['var(--primary)','#FF3D3D'],
		  
		  //type: 'solid',
		//colors:['var(--primary)','#FF3D3D'],
		fill: {
			type: 'solid',
		  opacity: .9,
		},
        grid: {
          borderColor: '#f1f1f1',
		  xaxis: {
            lines: {
              show: false
            }
          },
		  yaxis: {
            lines: {
              show: true
            }
          },
        },
		 responsive: [{
			breakpoint: 575,
			options: {
				
			}
		 }]
        };
		var chartArea = new ApexCharts(document.querySelector("#activityz"), optionsArea);
        chartArea.render();

	}
	var piechart = function(){
		 var options = {
			  series: [45, 10, 80, ],
			  chart: {
			  type: 'donut',
			  height:250,
			},
			dataLabels: {
				enabled: false
			},
			legend: {
				
				show:false
			},
			stroke: {
				
				width:0,
			},
			fill: {
				colors: ['var(--primary)', '#FF8D0E' ,'#46D767'],
			},
			responsive: [{
			  breakpoint: 601,
			  options: {
				chart: {
				  width: 200,
				  height: 200
				},
				legend: {
				  position: 'bottom'
				}
			  },
			  breakpoint:361,
			  options: {
				chart: {
				  width: 270,
				  height: 200
				  
				},
				legend: {
				  position: 'bottom'
				}
			  }
			 
			}]
			};

			var piechart = new ApexCharts(document.querySelector("#piechart"), options);
			piechart.render();
	}

  
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
 
	/* Function ============ */
		return {
			init:function(){
				
			},
			
			
			load:function(){
				
				activityz();
				piechart();
				
				
			},
			
			resize:function(){
			}
		}
	
	}();

	
		
	jQuery(window).on('load',function(){
		setTimeout(function(){
			dzChartlist.load();
		}, 1000); 
		
	});

     

})(jQuery);
