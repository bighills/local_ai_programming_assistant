#!/usr/bin/env python3
"""
Jieba Chinese word segmenter for AI Programming Assistant KB.

Usage:
    echo "待分词的文本" | python3 jieba_segment.py
    cat chunks.txt | python3 jieba_segment.py

Input (stdin):
    One line of text per record.

Output (stdout):
    Space-joined segmented words, one line per input line.
    Empty lines are output as empty lines.
"""
import sys
import os
import jieba

# Suppress jieba's loading messages to stderr to keep stdout clean.
# setLogLevel 接受日志级别数字（CRITICAL=50/ERROR=40/WARNING=30/INFO=20/DEBUG=10）。
# 加载消息（"Building prefix dict..."）是 INFO 级别，设为 20 无法抑制，必须 >= 30。
jieba.setLogLevel(30)  # WARNING level
jieba.initialize()

# Optionally add domain-specific terms to improve segmentation accuracy.
DOMAIN_TERMS = [
    # Data structures
    '二叉搜索树', '平衡二叉树', '二叉平衡树',
    '二叉排序树', '线索二叉树', '哈夫曼树', '最优二叉树',
    '完全二叉树', '满二叉树',
    '最小堆', '最大堆', '小根堆', '大根堆',
    '双向链表', '循环链表', '顺序表',
    '斐波那契', '卡特兰数',
    '最小生成树', '最短路径', '关键路径',
    '邻接矩阵', '邻接表', '逆邻接表',
    '十字链表', '多重链表',
    '循环队列', '链式队列', '链式栈',
    '共享栈', '双端队列', '优先队列',
    # Tree traversal
    '前序遍历', '中序遍历', '后序遍历', '层序遍历',
    '先序遍历', '递归遍历', '非递归遍历',
    '层次遍历', '深度遍历', '广度遍历',
    '树的遍历', '图的遍历',
    # More data structures
    'B+树', 'B树', '红黑树', 'AVL树',
    '散列表', '哈希表', '哈希冲突', '哈希函数',
    '开放地址法', '链地址法', '再哈希法',
    '线性探测', '二次探测', '平方探测',
    '平均查找长度', '填装因子', '装填因子',
    # Algorithms
    '快速排序', '归并排序', '希尔排序',
    '冒泡排序', '选择排序', '插入排序',
    '基数排序', '桶排序', '计数排序',
    '深度优先', '广度优先', '二分查找', '折半查找',
    '深度优先搜索', '广度优先搜索',
    '贪心算法', '分治算法', '回溯算法',
    '动态规划', '分支限界', '滑动窗口',
    '双指针', '前缀和', '差分数组',
    '拓扑排序', '迪杰斯特拉', '弗洛伊德',
    '普里姆算法', '克鲁斯卡尔',
    # Programming concepts
    '构造函数', '析构函数', '虚函数表',
    '纯虚函数', '智能指针', '右值引用',
    '移动语义', '完美转发', '匿名函数',
    '闭包', '回调函数', '递归函数',
    '函数重载', '运算符重载', '虚继承',
    '多重继承', '模板特化', '偏特化',
    '面向对象', '面向过程', '泛型编程',
    '函数式编程', '异常处理', '命名空间',
    # OS
    '进程调度', '页面置换', '虚拟内存',
    '文件系统', '死锁避免', '死锁检测',
    '银行家算法', '电梯调度',
    '生产者消费者', '读者写者', '哲学家就餐',
    # Network
    '三次握手', '四次挥手', '拥塞控制',
    '流量控制', '超时重传', '慢开始',
    '拥塞避免', '快速重传', '快速恢复',
    # Database
    '关系数据库', '关系模型', '关系模式',
    '函数依赖', '范式分解', '事务隔离',
    '并发控制', '封锁协议', '两段锁',
    # Software engineering
    '需求分析', '概要设计', '详细设计',
    '单元测试', '集成测试', '系统测试',
    '验收测试', '黑盒测试', '白盒测试',
    # Design patterns
    '单例模式', '工厂模式', '观察者模式',
    '装饰者模式', '适配器模式', '代理模式',
    '策略模式', '模板模式', '命令模式',
    # Time complexity
    '时间复杂度', '空间复杂度',
    # ===== 新增:试题常见术语 =====
    # 数据结构综合
    '线性表', '顺序表', '单链表', '双链表',
    '栈顶', '栈底', '队头', '队尾', '队首',
    '进栈', '出栈', '入队', '出队', '判空',
    '取栈顶', '判断空', '置空',
    # 树/二叉树
    '非终端结点', '终端结点', '叶结点', '分支结点', '内部结点',
    '孩子结点', '兄弟结点', '祖先结点', '子孙结点', '父结点',
    '左右孩子', '左子树', '右子树', '左右子树',
    '左指针', '右指针', '头指针', '尾指针',
    '关键字', '关键码', '数据域', '指针域', '链域',
    '中序线索', '先序线索', '后序线索',
    '二叉链表', '三叉链表', '孩子兄弟', '双亲表示',
    '树的深度', '树的度',
    '完全二叉树', '满二叉树', '二叉排序树',
    '平衡因子', '最小不平衡',
    # 图
    '有向图', '无向图', '有向网', '无向网', '连通图', '非连通图',
    '连通分量', '强连通分量', '极大连通', '极小连通',
    '生成树', '生成森林',
    '入栈', '出栈序列', '入栈序列',
    # 查找
    '分块查找', '索引顺序', '二分查找', '折半查找',
    '顺序查找', '平均查找', '比较次数', '查找成功', '查找失败',
    '装填因子', '负载因子',
    '线性探测再散列', '二次探测再散列', '伪随机探测',
    '链地址', '再哈希',
    # 排序
    '每趟排序', '排序趟数', '比较和移动',
    '简单选择', '直接选择', '直接插入',
    '待排序列', '初始序列', '有序序列',
    '冒泡', '堆排序', '快速排序', '归并排序',
    '升序', '降序', '递增', '递减', '非递减',
    # 串/数组
    '子串', '主串', '模式串', '目标串',
    'next数组', 'nextval', 'KMP',
    '特殊矩阵', '稀疏矩阵', '压缩存储', '对称矩阵', '三角矩阵',
    '行优先', '列优先', '存储地址', '下标',
    '广义表', '表头', '表尾', '原子', '子表', '深度',
    # 通用编程
    '形参', '实参', '值传递', '地址传递', '传值', '传址',
    '全局变量', '局部变量', '静态变量',
    '递归调用', '递归出口', '递归体', '递归深度',
    '主函数', '自定义函数', '库函数',
    '类型定义', '结构体', '共用体', '自定义类型',
    '文件包含', '宏定义', '条件编译',
    # 数据与计算
    '数据采集', '数据分析', '数据可视化',
    '爬虫', '数据清洗', '数据整理', '数据存储',
    '折线图', '柱状图', '饼图', '散点图',
    '描述统计', '推断统计',
    '预测分析', '分类算法', '聚类算法', '回归分析',
    '监督学习', '无监督学习', '强化学习',
    '训练集', '测试集', '验证集', '特征工程',
    '交叉验证', '过拟合', '欠拟合',
    '循环结构', '选择结构', '分支结构', '顺序结构',
    '遍历列表', '遍历字典', '文件读写', '异常捕获',
    # Python 相关
    '列表推导', '字典推导', '生成器', '装饰器',
    '字符串格式化', '切片操作', '索引访问',
    'pip', 'numpy', 'pandas', 'matplotlib',
    # 考试题型
    '单选题', '多选题', '判断题', '填空题', '简答题',
    '程序分析', '程序填空', '程序设计',
]
for term in DOMAIN_TERMS:
    jieba.add_word(term)


def segment_line(line: str) -> str:
    """Segment one line of Chinese text, return space-joined words."""
    line = line.strip()
    if not line:
        return ''
    # lcut = precise mode (default), most suitable for indexing.
    words = jieba.lcut(line)
    # Filter: keep words >= 2 chars, OR single-char meaningful tokens (not pure stopwords)
    filtered = []
    meaningful_single = {'栈', '堆', '树', '图', '类', '值', '型', '队', '度', '根'}
    for w in words:
        w = w.strip()
        if not w:
            continue
        if len(w) >= 2:
            filtered.append(w.lower() if w.isascii() else w)
        elif w in meaningful_single:
            filtered.append(w)
        # else: skip single-char stopwords like '的', '了', '是', etc.
    return ' '.join(filtered)


def main():
    # 显式声明 UTF-8：stdin/stdout 默认编码取自进程 locale（getpreferredencoding），
    # 在未设 LANG 的容器/cron 环境下可能为 ASCII，导致中文输入解码崩溃或输出乱码。
    # 知识库内容与 DOMAIN_TERMS 词典均为中文，UTF-8 是硬性前提。
    if hasattr(sys.stdin, 'reconfigure'):
        sys.stdin.reconfigure(encoding='utf-8')
    if hasattr(sys.stdout, 'reconfigure'):
        sys.stdout.reconfigure(encoding='utf-8')
    for line in sys.stdin:
        result = segment_line(line)
        sys.stdout.write(result + '\n')
        sys.stdout.flush()


if __name__ == '__main__':
    main()